export function filterActions(actions, query) {
    const keyword = query.trim().toLocaleLowerCase()
    if (!keyword) return actions
    return actions.filter(action => String(action.action_name || '')
        .toLocaleLowerCase()
        .includes(keyword))
}
