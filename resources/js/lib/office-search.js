export function filterOffices(offices, query) {
    const keyword = query.trim().toLocaleLowerCase()
    if (!keyword) return offices
    return offices.filter(office => [office.office_name, office.office_code]
        .some(value => String(value || '').toLocaleLowerCase().includes(keyword)))
}
