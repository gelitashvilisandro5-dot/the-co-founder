import { useState, useEffect } from 'react';
import { Menu, Sparkles } from 'lucide-react';
import Sidebar from './components/Sidebar';
import ChatArea from './components/ChatArea';
import InputDock from './components/InputDock';

function App() {
  const [appState, setAppState] = useState('landing'); // 'landing' | 'chat'
  const [messages, setMessages] = useState([]);
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isThinking, setIsThinking] = useState(false);
  const [isMobile, setIsMobile] = useState(window.innerWidth <= 900);

  useEffect(() => {
    const handleResize = () => setIsMobile(window.innerWidth <= 900);
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const handleStartChat = () => {
    setAppState('chat');
  };

  const toggleSidebar = () => setIsSidebarOpen(!isSidebarOpen);

  const handleSendMessage = async (text, files) => {
    // Add user message
    const newMessages = [...messages];
    if (text) newMessages.push({ role: 'user', content: text });
    if (files.length > 0) {
      const names = files.map(f => f.name).join(', ');
      newMessages.push({ role: 'user', content: `Attached: ${names}` });
    }
    setMessages(newMessages);
    setIsThinking(true);

    // Prepare payload
    try {
      let parts = [];
      if (text) parts.push({ text });

      // Handle files (convert to base64)
      if (files.length > 0) {
        const filePromises = files.map(f => new Promise((resolve, reject) => {
          const reader = new FileReader();
          reader.onloadend = () => resolve({
            inlineData: {
              data: reader.result.split(',')[1],
              mimeType: f.type
            }
          });
          reader.onerror = reject;
          reader.readAsDataURL(f);
        }));
        const fileParts = await Promise.all(filePromises);
        parts = [...parts, ...fileParts];
      }

      const response = await fetch('/ask_expert_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ contents: [{ parts }] })
      });

      if (!response.ok) throw new Error(response.statusText);

      const data = await response.json();
      const aiText = data.candidates[0].content.parts[0].text;

      setMessages(prev => [...prev, { role: 'ai', content: aiText }]);

    } catch (error) {
      console.error("API Error:", error);
      setMessages(prev => [...prev, { role: 'ai', content: `**Error**: ${error.message}. Please try again.` }]);
    } finally {
      setIsThinking(false);
    }
  };

  return (
    <>
      {/* HEADER */}
      <header className="header">
        <div className="hamburger-menu" onClick={toggleSidebar}>
          <Menu />
        </div>
        <img src="/cofonder.png" alt="COFUNDR" className="logo" />
      </header>

      {/* SIDEBAR (Mobile & Desktop) */}
      {/* SIDEBAR (Mobile Only) */}
      {isMobile && (
        <Sidebar
          isOpen={isSidebarOpen}
          onClose={() => setIsSidebarOpen(false)}
          isMobile={true}
        />
      )}

      {/* LANDING VIEW */}
      <div className={`landing-view ${appState === 'chat' ? 'hidden' : ''}`} style={{ opacity: appState === 'chat' ? 0 : 1, pointerEvents: appState === 'chat' ? 'none' : 'auto' }}>
        <div className="hero-section">
          <h1 className="hero-title">Startups Die From Delusion</h1>
          <p className="hero-subtitle">
            Meet The Co-Founder: A ruthless strategic engine powered by Gemini 3 Pro.
            I'm not here to be polite. I'm here to crush your hallucinations with logic.
          </p>
          <button className="start-chat-btn" onClick={handleStartChat}>
            <Sparkles size={18} /> Face The Truth
          </button>
        </div>
      </div>

      {/* CHAT VIEW */}
      <div className={`chat-view ${appState === 'chat' ? 'active' : ''}`} style={{ opacity: appState === 'chat' ? 1 : 0, visibility: appState === 'chat' ? 'visible' : 'hidden' }}>
        <div className="workspace-container">
          {!isMobile && <Sidebar isMobile={false} />}

          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', height: '100%', maxWidth: '100%' }}>
            <ChatArea messages={messages} isThinking={isThinking} />
            <InputDock onSendMessage={handleSendMessage} />
          </div>
        </div>
      </div>
    </>
  );
}

export default App;
