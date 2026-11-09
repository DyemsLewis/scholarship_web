import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useConfirmationDialog } from '../../resources/js/composables/useConfirmationDialog.js';
import { preparationQuery } from '../../resources/js/support/scholarshipPreparationContext.js';

const savedFile = { id: 42, document_name: 'School ID', original_name: 'school-id.pdf' };

function setup(page, axios, exposed) {
    const source = readFileSync(new URL('../../resources/js/pages/' + page + '.vue', import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    let unmount;
    const bindings = {
        computed, ref, useConfirmationDialog, preparationQuery,
        window: { location: { search: '' }, axios },
        document: { getElementById: () => ({ dataset: { applicationId: '3' } }) },
        onMounted() {},
        onBeforeUnmount: (callback) => { unmount = callback; },
    };
    const state = new Function(...Object.keys(bindings), script + '\nreturn {' + exposed + '};')(...Object.values(bindings));
    return { ...state, unmount };
}

test('My Documents previews directly and removes a reusable file only after confirmation', async () => {
    const requests = [];
    const state = setup('UserDocumentsPage', {
        delete: async (url) => { requests.push(url); },
        get: async () => ({ data: { applications: [], document_options: [], prepared_documents: [] } }),
    }, 'isLoading, preparedDocuments, previewDocument, openDocumentPreview, closeDocumentPreview, deletePreparedDocument, confirmation, confirmConfirmation, cancelConfirmation');
    state.preparedDocuments.value = [savedFile, { ...savedFile, id: 43 }];
    state.isLoading.value = false;
    const [file, other] = state.preparedDocuments.value;
    state.openDocumentPreview(file);
    assert.equal(state.previewDocument.value, file);
    assert.equal(state.confirmation.open, false);
    state.closeDocumentPreview();

    const cancelled = state.deletePreparedDocument(file);
    assert.equal(state.confirmation.open, true);
    assert.match(state.confirmation.title, /My Documents/);
    assert.match(state.confirmation.message, /school-id\.pdf.*already submitted with applications will stay attached/);
    assert.equal(state.confirmation.cancelLabel, 'Keep file');
    await state.deletePreparedDocument(other);
    assert.equal(requests.length, 0);
    state.cancelConfirmation();
    await cancelled;
    assert.equal(state.preparedDocuments.value.length, 2);

    const confirmed = state.deletePreparedDocument(file);
    assert.equal(requests.length, 0);
    state.confirmConfirmation();
    state.confirmConfirmation();
    await confirmed;
    assert.deepEqual(requests, ['/dashboard/student-documents/42']);
});

test('application file removal preserves the submitted copy on cancel or unmount and rejects a stale row', async () => {
    const requests = [];
    const state = setup('UserApplicationDetailPage', {
        delete: async (url) => {
            requests.push(url);
            return { data: { application: { id: 3, documents: [] } } };
        },
    }, 'application, deleteDocument, confirmation, confirmConfirmation, cancelConfirmation');
    state.application.value = { id: 3, documents: [savedFile] };
    const file = state.application.value.documents[0];

    const cancelled = state.deleteDocument(file);
    assert.match(state.confirmation.title, /this application/);
    assert.match(state.confirmation.message, /required, upload a replacement/);
    assert.equal(requests.length, 0);
    state.cancelConfirmation();
    await cancelled;
    assert.equal(state.application.value.documents.length, 1);

    const stale = state.deleteDocument(file);
    state.application.value = { id: 3, documents: [{ ...savedFile, original_name: 'replacement.pdf' }] };
    state.confirmConfirmation();
    await stale;
    assert.equal(requests.length, 0);

    const unmounted = state.deleteDocument(state.application.value.documents[0]);
    state.unmount();
    await unmounted;
    assert.equal(state.confirmation.open, false);
    assert.equal(requests.length, 0);

    const confirmed = state.deleteDocument(state.application.value.documents[0]);
    state.confirmConfirmation();
    await confirmed;
    assert.deepEqual(requests, ['/dashboard/documents/42']);
    assert.equal(state.application.value.documents.length, 0);
});
