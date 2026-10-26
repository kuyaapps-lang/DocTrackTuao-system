import { ref } from 'vue'

// A new key resets pre-dark-mode browser preferences during this rollout.
export const THEME_STORAGE_KEY = 'doctrack_theme_v2'
export const LIGHT_THEME = 'light'
export const DARK_THEME = 'dark'

const isTheme = value => value === LIGHT_THEME || value === DARK_THEME
const theme = ref(LIGHT_THEME)

export const savedTheme = storage => {
    try {
        const value = storage?.getItem(THEME_STORAGE_KEY)
        return isTheme(value) ? value : null
    } catch {
        return null
    }
}

export const resolveTheme = ({ storage, matchMedia } = {}) => {
    // Every URL load starts in light mode. The toggle remains available for
    // the current visit, but a previous browser preference is not restored.
    return LIGHT_THEME
}

export const applyTheme = (nextTheme, root = document.documentElement) => {
    const resolved = isTheme(nextTheme) ? nextTheme : LIGHT_THEME
    root.classList.toggle('dark', resolved === DARK_THEME)
    root.dataset.theme = resolved
    root.style.colorScheme = resolved
    theme.value = resolved
    return resolved
}

export const initializeTheme = (environment = window) => applyTheme(resolveTheme({
    storage: environment.localStorage,
    matchMedia: environment.matchMedia?.bind(environment),
}), environment.document.documentElement)

export const toggleTheme = (environment = window) => {
    const nextTheme = theme.value === DARK_THEME ? LIGHT_THEME : DARK_THEME
    try {
        environment.localStorage.setItem(THEME_STORAGE_KEY, nextTheme)
    } catch {
        // Theme preference remains available for this browser session.
    }
    return applyTheme(nextTheme, environment.document.documentElement)
}

export const useTheme = () => ({ theme, toggleTheme })
