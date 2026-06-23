export function collectRemovedCommentIds(comments, ids) {
    const removed = new Set(ids.filter((id) => comments.has(id)));
    if (removed.size === 0) return removed;

    // A deleted root implicitly removes its loaded replies from the tree.
    let changed;
    do {
        changed = false;
        comments.forEach((comment, id) => {
            if (comment.parentId && removed.has(comment.parentId) && !removed.has(id)) {
                removed.add(id);
                changed = true;
            }
        });
    } while (changed);

    return removed;
}

export function sameReactions(left, right) {
    if (left.length !== right.length) return false;

    const byEmoji = new Map(left.map((reaction) => [reaction.emoji, reaction]));
    return right.every((reaction) => {
        const current = byEmoji.get(reaction.emoji);
        return current && current.count === reaction.count && current.mine === reaction.mine;
    });
}
