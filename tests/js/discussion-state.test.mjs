import assert from 'node:assert/strict';
import test from 'node:test';

import { collectRemovedCommentIds } from '../../assets/discussion-state.mjs';

test('deleting a root removes its loaded replies', () => {
    const comments = new Map([
        [1, { id: 1, parentId: null }],
        [2, { id: 2, parentId: 1 }],
        [3, { id: 3, parentId: 1 }],
        [4, { id: 4, parentId: null }],
    ]);

    assert.deepEqual([...collectRemovedCommentIds(comments, [1])].sort(), [1, 2, 3]);
});

test('deleting a reply leaves its root and siblings intact', () => {
    const comments = new Map([
        [1, { id: 1, parentId: null }],
        [2, { id: 2, parentId: 1 }],
        [3, { id: 3, parentId: 1 }],
    ]);

    assert.deepEqual([...collectRemovedCommentIds(comments, [2])], [2]);
});
