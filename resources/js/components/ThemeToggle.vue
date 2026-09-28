<script setup>
import { Moon, Sun } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { DARK_THEME, useTheme } from '@/lib/theme'

const { theme, toggleTheme } = useTheme()
</script>

<template>
    <Button
        type="button"
        variant="outline"
        size="icon"
        class="doctrack-theme-toggle"
        :aria-label="theme === DARK_THEME ? 'Switch to light theme' : 'Switch to dark theme'"
        :title="theme === DARK_THEME ? 'Switch to light theme' : 'Switch to dark theme'"
        @click="toggleTheme()"
    >
        <Sun v-if="theme === DARK_THEME" aria-hidden="true" />
        <Moon v-else aria-hidden="true" />
    </Button>
</template>
