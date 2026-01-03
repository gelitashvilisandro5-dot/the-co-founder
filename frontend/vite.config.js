import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/ask_expert_api.php': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
      },
      '/check_status.php': {
        target: 'http://localhost:8080',
        changeOrigin: true,
         secure: false,
      },
      '/upload_insight.php': {
        target: 'http://localhost:8080',
        changeOrigin: true,
         secure: false,
      }
    }
  },
  build: {
    outDir: '../dist', // Build to root dist folder
    emptyOutDir: true,
  }
})
