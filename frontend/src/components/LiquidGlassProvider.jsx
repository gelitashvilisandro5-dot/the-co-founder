import { useEffect } from 'react';

const LiquidGlassProvider = ({ children }) => {
  useEffect(() => {
    let rafId = null;

    const handleMouseMove = (e) => {
      if (rafId) return;

      rafId = window.requestAnimationFrame(() => {
        const x = e.clientX / window.innerWidth;
        const y = e.clientY / window.innerHeight;
        document.documentElement.style.setProperty('--mouse-x', x.toFixed(3));
        document.documentElement.style.setProperty('--mouse-y', y.toFixed(3));
        rafId = null;
      });
    };

    const isFinePointer = window.matchMedia('(pointer: fine)').matches;
    if (isFinePointer) {
      window.addEventListener('mousemove', handleMouseMove, { passive: true });
    }

    return () => {
      if (rafId) {
        window.cancelAnimationFrame(rafId);
      }
      if (isFinePointer) {
        window.removeEventListener('mousemove', handleMouseMove);
      }
    };
  }, []);

  return children;
};

export default LiquidGlassProvider;
