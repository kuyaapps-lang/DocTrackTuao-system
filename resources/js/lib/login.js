export const loginErrorMessage = (status, backendMessage) => {
    if (status === 429) {
        return 'Too many login attempts. Please try again later.'
    }

    return backendMessage || 'Login failed.'
}
