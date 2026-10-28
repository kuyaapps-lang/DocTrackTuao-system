export function firstSuggestedDestination(officeIds, availableOffices) {
    const first = officeIds[0]
    return availableOffices.find(office => String(office.id) === String(first)) || null
}

export function suggestedDestinations(officeIds, availableOffices) {
    const officesById = new Map(availableOffices.map(office => [String(office.id), office]))
    const used = new Set()
    return officeIds.flatMap((id, index) => {
        const key = String(id)
        const office = officesById.get(key)
        if (!office || used.has(key)) return []
        used.add(key)
        return [{ ...office, routePosition: index + 1 }]
    })
}
