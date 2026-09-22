export const loginErrorMessage = (status, backendMessage) => {
    if (status === 429) {
        return 'Maximum login attempts used. Click \'Forgot Password?\' or contact the System Administrator.'
    }

    return backendMessage || 'Login failed.'
}
