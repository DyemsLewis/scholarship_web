const activeLocks = new WeakMap();

export function lockPageScroll(doc = document, view = window) {
    let state = activeLocks.get(doc);
    if (!state) {
        const root = doc.documentElement;
        const properties = ['overflow-y', 'scrollbar-gutter'].map((name) => ({
            name, value: root.style.getPropertyValue(name), priority: root.style.getPropertyPriority(name),
        }));
        state = { root, properties, count: 0 };
        activeLocks.set(doc, state);
        // Lock the viewport, not body: body overflow creates a new sticky scroll container.
        if (view.innerWidth > root.clientWidth) {
            const gutter = view.getComputedStyle(root).scrollbarGutter;
            root.style.setProperty('scrollbar-gutter', gutter?.includes('stable') ? gutter : 'stable');
        }
        root.style.setProperty('overflow-y', 'hidden');
    }
    state.count++;
    let released = false;
    return () => {
        if (released) return;
        released = true;
        if (--state.count > 0) return;
        for (const property of state.properties) {
            if (property.value) state.root.style.setProperty(property.name, property.value, property.priority);
            else state.root.style.removeProperty(property.name);
        }
        activeLocks.delete(doc);
    };
}
