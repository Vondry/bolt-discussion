<?php

declare(strict_types=1);

namespace Bolt\Discussion\Controller\Api;

use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Exception\ValidationException;
use Bolt\Discussion\Service\DiscussionManager;
use Bolt\Discussion\Service\VisitorTokenProvider;
use Bolt\Extension\ExtensionController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/discussion/api')]
class DiscussionApiController extends ExtensionController
{
    private const CSRF_ID = 'bolt_discussion';
    private const REFERENCE = '[A-Za-z0-9_.:-]{1,191}';
    private const DOMAIN = 'bolt_discussion';
    private const SYNC_POLL_LIMIT = 100;

    public function __construct(
        private readonly DiscussionManager $manager,
        private readonly VisitorTokenProvider $visitor,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/csrf/token', name: 'bolt_discussion_api_csrf_token', methods: ['GET'])]
    public function csrfToken(): JsonResponse
    {
        $response = new JsonResponse([
            'token' => $this->csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $this->finish($response);
    }

    #[Route('/{reference}', name: 'bolt_discussion_api_list', methods: ['GET'], requirements: ['reference' => self::REFERENCE])]
    public function list(string $reference, Request $request): JsonResponse
    {
        $canModerate = $this->visitor->isModerator();

        if ($request->query->has('since')) {
            // Polling: incremental fetch of comments newer than the given id.
            $since = filter_var($request->query->get('since'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if ($since === false) {
                return $this->finish(new JsonResponse(['error' => $this->t('Invalid polling cursor.')], Response::HTTP_BAD_REQUEST));
            }

            $syncIds = $this->syncIds($request);
            if ($syncIds === null) {
                return $this->finish(new JsonResponse(['error' => $this->t('Invalid comment polling request.')], Response::HTTP_BAD_REQUEST));
            }

            $payload = $this->manager->getThread($reference, $since, $canModerate);
            if ($syncIds !== []) {
                $payload += $this->manager->getPollUpdates($reference, $syncIds, $canModerate);
            }
        } else {
            // Initial / "load more": cursor-paginated page of root comments.
            $before = $request->query->has('before') ? (int) $request->query->get('before') : null;
            $perPage = $request->query->has('per_page') ? (int) $request->query->get('per_page') : null;
            $payload = $this->manager->getPage($reference, $before, $perPage, $canModerate);
        }

        $payload['canModerate'] = $canModerate;

        return $this->finish(new JsonResponse($payload));
    }

    #[Route('/{reference}', name: 'bolt_discussion_api_create', methods: ['POST'], requirements: ['reference' => self::REFERENCE])]
    public function create(string $reference, Request $request): JsonResponse
    {
        $input = $this->input($request);

        if (! $this->isCsrfTokenValid(self::CSRF_ID, (string) ($input['_token'] ?? ''))) {
            return $this->invalidCsrf('Invalid security token. Please reload the page.');
        }

        try {
            $result = $this->manager->createComment($reference, $input, $request);
        } catch (ValidationException $e) {
            return $this->finish(new JsonResponse(['error' => $this->t($e->getMessage(), $e->getParameters())], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->finish(new JsonResponse($result, Response::HTTP_CREATED));
    }

    #[Route('/comment/{id}', name: 'bolt_discussion_api_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(DiscussionComment $comment, Request $request): JsonResponse
    {
        if (! $this->visitor->isModerator()) {
            return $this->finish(new JsonResponse(['error' => $this->t('Not authorised.')], Response::HTTP_FORBIDDEN));
        }
        if (! $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->invalidCsrf('Invalid security token.');
        }

        $this->manager->deleteComment($comment);

        return $this->finish(new JsonResponse(['status' => 'deleted', 'id' => $comment->getId()]));
    }

    #[Route('/comment/{id}/reaction', name: 'bolt_discussion_api_reaction', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reaction(DiscussionComment $comment, Request $request): JsonResponse
    {
        if ($comment->getStatus() !== CommentStatus::Published) {
            return $this->finish(new JsonResponse(['error' => $this->t('Comment not available.')], Response::HTTP_NOT_FOUND));
        }

        $input = $this->input($request);
        if (! $this->isCsrfTokenValid(self::CSRF_ID, (string) ($input['_token'] ?? ''))) {
            return $this->invalidCsrf('Invalid security token.');
        }

        try {
            $result = $this->manager->toggleReaction($comment, (string) ($input['emoji'] ?? ''));
        } catch (ValidationException $e) {
            return $this->finish(new JsonResponse(['error' => $this->t($e->getMessage(), $e->getParameters())], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->finish(new JsonResponse($result));
    }

    /**
     * @param array<string, string|int> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, self::DOMAIN);
    }

    private function invalidCsrf(string $message): JsonResponse
    {
        return $this->finish(new JsonResponse([
            'error' => $this->t($message),
            'code' => 'invalid_csrf',
        ], Response::HTTP_BAD_REQUEST));
    }

    /**
     * Read input from either a JSON body or a classic form post.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $request->getContent(), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    /**
     * Parse the bounded list of already-loaded comments whose visibility and
     * reaction aggregates should be refreshed as part of the normal poll.
     *
     * @return int[]|null
     */
    private function syncIds(Request $request): ?array
    {
        if (! $request->query->has('sync_ids')) {
            return [];
        }

        $raw = $request->query->get('sync_ids');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $value) {
            if (! ctype_digit($value) || (int) $value < 1) {
                return null;
            }
            $ids[(int) $value] = (int) $value;
            if (count($ids) > self::SYNC_POLL_LIMIT) {
                return null;
            }
        }

        return array_values($ids);
    }

    /**
     * Attach a freshly-issued visitor cookie (if any) before returning.
     */
    private function finish(JsonResponse $response): JsonResponse
    {
        $this->visitor->applyCookie($response);

        return $response;
    }
}
