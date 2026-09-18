import assert from 'node:assert/strict';
import test from 'node:test';

import api from '../../resources/js/services/api.js';

test('API client uses the current browser origin', () => {
    assert.equal(api.defaults.baseURL, '/api');
});
