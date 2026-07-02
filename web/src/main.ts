import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { router } from './router'
import { i18n } from './i18n'
import App from './App.vue'
import { vMath } from './directives/vMath'
import './styles/main.css'
import './styles/custom-theme.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(i18n)
app.directive('math', vMath)
app.mount('#app')
