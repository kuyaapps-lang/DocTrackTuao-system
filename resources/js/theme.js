// Keep the pre-Vue paint aligned with the application: each URL load starts light.
const theme = 'light'

document.documentElement.classList.toggle('dark', theme === 'dark')
document.documentElement.dataset.theme = theme
document.documentElement.style.colorScheme = theme
