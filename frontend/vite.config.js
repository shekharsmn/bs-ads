import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      // Keep the proxy on the same address Laravel's local server binds to.
      // On Windows, `localhost` can resolve to ::1 and leave requests pending
      // when `php artisan serve` is listening only on 127.0.0.1.
      '/sanctum': 'http://127.0.0.1:8000',
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
