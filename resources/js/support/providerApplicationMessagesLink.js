export function providerApplicationMessagesLinks(user) {
    if (!user?.can_post_scholarships || !user.permissions?.includes('manage_application_messages')) return [];
    return [{
        href: '/provider/workspaces/messages',
        label: 'Messages',
        icon: 'fa-regular fa-comment-dots',
    }];
}
