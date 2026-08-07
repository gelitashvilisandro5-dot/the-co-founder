import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import 'katex/dist/katex.min.css'; // Import KaTeX CSS
import App from './App.jsx'
import LiquidGlassProvider from './components/LiquidGlassProvider.jsx'

// Glass Pulse utility (can be called from anywhere)
window.applyGlassPulse = (element) => {
  if (element) {
    element.classList.add('glass-pulse');
    setTimeout(() => element.classList.remove('glass-pulse'), 600);
  }
};

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <LiquidGlassProvider>
      <App />
    </LiquidGlassProvider>
  </StrictMode>,
)
