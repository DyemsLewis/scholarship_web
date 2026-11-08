import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createRenderer, h, nextTick } from 'vue';

const read = (file) => readFileSync(new URL(`../../resources/${file}`, import.meta.url), 'utf8');
const { descriptor } = parse(read('js/components/StaffTableIdentity.vue'));
const compiled = compileScript(descriptor, { id: 'staff-table-identity', inlineTemplate: true }).content
    .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
const { default: Identity } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

function mountIdentity() {
    const node = (type) => ({
        type,
        props: {},
        children: [],
        getAttribute(key) { return this.props[key] ?? null; },
    });
    const root = node('root');
    const { render } = createRenderer({
        createElement: node,
        createText: () => node('text'),
        createComment: () => node('comment'),
        insert(child, parent, anchor = null) {
            child.parent = parent;
            const index = anchor ? parent.children.indexOf(anchor) : parent.children.length;
            parent.children.splice(index, 0, child);
        },
        remove(child) {
            const parent = child.parent;
            parent.children.splice(parent.children.indexOf(child), 1);
            child.parent = null;
        },
        patchProp(el, key, _, value) { el.props[key] = value; },
        setText() {},
        setElementText() {},
        parentNode: (el) => el.parent,
        nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    return { set: (props) => render(h(Identity, props), root), image: () => root.children[0].children[0] };
}

const fail = async (image) => {
    image.props.onError({ currentTarget: image });
    await nextTick();
};

test('staff table portraits replace a broken source once, stop on a broken placeholder and recover on source change', async () => {
    const cell = mountIdentity();
    cell.set({ src: '/photo.jpg' });
    const original = cell.image();
    assert.equal(original.props.src, '/photo.jpg');
    assert.match(original.props.class, /object-cover/);
    await fail(original);
    assert.equal(cell.image().props.src, '/images/demo/default-applicant-avatar.jpg');
    await fail(cell.image());
    assert.equal(cell.image().type, 'i');
    assert.match(cell.image().props.class, /fa-user\b/);
    cell.set({ src: '/replacement.jpg' });
    await nextTick();
    await fail(original);
    assert.equal(cell.image().props.src, '/replacement.jpg');
});

test('staff table logos retain their shape and never substitute a cover or retry the same fallback', async () => {
    const cell = mountIdentity();
    cell.set({ kind: 'provider', src: '/provider.png' });
    assert.match(cell.image().props.class, /object-contain/);
    await fail(cell.image());
    assert.match(cell.image().props.class, /fa-building-columns/);
    cell.set({ kind: 'program', src: null });
    assert.equal(cell.image().props.src, '/uploads/scholarship-default.jpg');
    cell.set({ kind: 'program', src: '/uploads/scholarship-default.jpg' });
    await nextTick();
    await fail(cell.image());
    assert.match(cell.image().props.class, /fa-graduation-cap/);
});

test('review and program queues use dedicated branding fields and common action styles', () => {
    const admin = read('js/pages/admin/reviews/ReviewQueuePage.vue');
    const provider = read('js/pages/provider/programs/ProgramDirectoryPage.vue');
    assert.match(admin, /:src="identityKind === 'applicant' \? item.photo_url : item.logo_url"/);
    assert.match(provider, /kind="program" :src="program.logo_url"/);
    for (const page of [admin, provider]) {
        assert.doesNotMatch(page, /<StaffTableIdentity[^>]+(?:cover_image_url|image_url)/);
        assert.match(page, /class="staff-row-action\b[^"]*"[^>]+:aria-label=/);
    }
    assert.match(admin, /data-file-preview="true"[^>]+class="staff-row-action/);
    assert.match(provider, /queueState.link\(program.record_url\)/);
});
