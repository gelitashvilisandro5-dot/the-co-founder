import { useState, useEffect, useRef } from 'react';
import { Menu } from 'lucide-react';
import Sidebar from './components/Sidebar';
import ChatArea from './components/ChatArea';
import InputDock from './components/InputDock';

function App() {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isThinking, setIsThinking] = useState(false);
  const [isGenerating, setIsGenerating] = useState(false);
  const [isMobile, setIsMobile] = useState(window.innerWidth <= 900);

  const [chats, setChats] = useState([]);
  const [activeChat, setActiveChat] = useState(null);
  const [messages, setMessages] = useState([]);

  const [files, setFiles] = useState([]);
  const [sessionFiles, setSessionFiles] = useState([]);
  const [editText, setEditText] = useState('');
  const abortControllerRef = useRef(null);

  useEffect(() => {
    const handleResize = () => setIsMobile(window.innerWidth <= 900);
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  // Open directly in chat mode with one empty chat.
  useEffect(() => {
    if (chats.length > 0) return;
    const newChat = {
      id: Date.now().toString(),
      title: 'New Chat',
      messages: [],
      timestamp: Date.now()
    };
    setChats([newChat]);
    setActiveChat(newChat.id);
    setMessages([]);
  }, [chats.length]);

  const generateChatTitle = (text) => {
    if (!text) return 'New Chat';
    const cleaned = text.replace(/[#*_`]/g, '').trim();
    return cleaned.length > 35 ? `${cleaned.substring(0, 35)}...` : cleaned;
  };

  const handleNewChat = () => {
    const newChat = {
      id: Date.now().toString(),
      title: 'New Chat',
      messages: [],
      timestamp: Date.now()
    };
    setChats((prev) => [newChat, ...prev]);
    setActiveChat(newChat.id);
    setMessages([]);
  };

  const handleSelectChat = (chatId) => {
    const chat = chats.find((c) => c.id === chatId);
    if (chat) {
      setActiveChat(chatId);
      setMessages(chat.messages);
    }
    if (isMobile) {
      setIsSidebarOpen(false);
    }
  };

  const toggleSidebar = () => setIsSidebarOpen(!isSidebarOpen);

  const readFileAsDataURL = (file) => {
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onloadend = () => resolve({
        name: file.name,
        type: file.type,
        data: reader.result
      });
      reader.readAsDataURL(file);
    });
  };

  const handleSendMessage = async (text) => {
    const currentFiles = [...files];
    let targetChatId = activeChat;

    if (!targetChatId) {
      const newChat = {
        id: Date.now().toString(),
        title: generateChatTitle(text),
        messages: [],
        timestamp: Date.now()
      };
      setChats((prev) => [newChat, ...prev]);
      setActiveChat(newChat.id);
      targetChatId = newChat.id;
    }

    const newMessages = [...messages];
    const fileAttachments = [];
    const allFileBase64 = [];

    if (currentFiles.length > 0) {
      for (const f of currentFiles) {
        const fileObj = await readFileAsDataURL(f);
        const dataUrlStr = fileObj.data;
        allFileBase64.push(dataUrlStr);

        if (f.type.startsWith('image/')) {
          fileAttachments.push(fileObj);
        }
      }
    }

    if (text || allFileBase64.length > 0 || currentFiles.length > 0) {
      newMessages.push({
        role: 'user',
        content: text,
        attachments: fileAttachments.length > 0 ? fileAttachments : null,
        fileNames: currentFiles.length > 0 ? currentFiles.map((f) => f.name) : null
      });
    }

    setMessages(newMessages);
    setIsThinking(true);
    setFiles([]);

    if (newMessages.length === 1 && text) {
      setChats((prev) => prev.map((c) =>
        c.id === targetChatId ? { ...c, title: generateChatTitle(text), timestamp: Date.now() } : c
      ));
    }

    try {
      let parts = [];
      if (text) parts.push({ text });

      if (currentFiles.length > 0) {
        const filePromises = currentFiles.map((f) => new Promise((resolve, reject) => {
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

      // Removed sessionFiles state append logic to prevent duplicate image sending
      if (allFileBase64.length > 0) {
        // no-op; we don't accumulate attachments across messages anymore
      }

      const payload = {
        stream: true,
        contents: [{ parts }],
        conversationHistory: newMessages.slice(-24).map((m) => ({
          role: m.role === 'ai' ? 'model' : 'user',
          content: m.content || ''
        }))
      };

      abortControllerRef.current = new AbortController();
      setIsGenerating(true);

      let response;
      try {
        // First attempt
        response = await fetch('/ask_expert_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream'
          },
          body: JSON.stringify(payload),
          signal: abortControllerRef.current.signal
        });
      } catch (err) {
        // In SPAs, tabs left open for hours often suffer from stale TCP sockets.
        // The fetch fails with TypeError. We retry instantly to force a new connection.
        if (err.name === 'TypeError' || String(err).includes('Failed to fetch') || String(err).includes('NetworkError')) {
          console.log('Stale socket detected, initiating immediate retry...');
          response = await fetch('/ask_expert_api.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'text/event-stream',
              'Cache-Control': 'no-cache',
              'Pragma': 'no-cache'
            },
            body: JSON.stringify(payload),
            signal: abortControllerRef.current.signal
          });
        } else {
          throw err;
        }
      }

      if (!response.ok) throw new Error(response.statusText);
      if (!response.body) throw new Error('Empty response stream');

      const reader = response.body.getReader();
      const decoder = new TextDecoder('utf-8');
      let aiText = '';
      let buffer = '';
      let isFirstChunk = true;

      const initialAiMessage = { role: 'ai', content: '', isStreaming: true };
      setMessages([...newMessages, initialAiMessage]);

      while (true) {
        const { value, done } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (const line of lines) {
          const trimmedLine = line.trim();
          if (!trimmedLine || !trimmedLine.startsWith('data: ')) continue;

          const jsonStr = trimmedLine.replace('data: ', '');
          if (jsonStr === '[DONE]') continue;

          try {
            const data = JSON.parse(jsonStr);

            if (data.text) {
              const chunkText = typeof data.text === 'string' ? data.text : JSON.stringify(data.text);
              aiText += chunkText;

              if (isFirstChunk) {
                setIsThinking(false);
                isFirstChunk = false;
              }

              setMessages((prev) => {
                const newMsgs = [...prev];
                if (newMsgs.length > 0) {
                  newMsgs[newMsgs.length - 1] = {
                    ...newMsgs[newMsgs.length - 1],
                    content: aiText,
                    isStreaming: true
                  };
                }
                return newMsgs;
              });
            }

            if (data.error) {
              throw new Error(typeof data.error === 'string' ? data.error : JSON.stringify(data.error));
            }
          } catch (e) {
            console.warn('Stream parse error or backend error:', e);
            if (trimmedLine.includes('"error":')) {
              aiText += `\n**Backend Error**: ${trimmedLine}`;
            }
          }
        }
      }

      const finalBotMessage = { role: 'ai', content: aiText, isStreaming: false };
      const finalMsgArray = [...newMessages, finalBotMessage];
      setMessages(finalMsgArray);

      setChats((prev) => prev.map((c) =>
        c.id === targetChatId ? { ...c, messages: finalMsgArray, timestamp: Date.now() } : c
      ));
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }

      console.error('API Error:', error);
      const errorContent = error.message || String(error);
      const errorMessages = [...newMessages, { role: 'ai', content: `**Error**: ${errorContent}. Please try again.` }];
      setMessages(errorMessages);

      setChats((prev) => prev.map((c) =>
        c.id === targetChatId ? { ...c, messages: errorMessages, timestamp: Date.now() } : c
      ));
    } finally {
      setIsThinking(false);
      setIsGenerating(false);
      abortControllerRef.current = null;
    }
  };

  const handleAddFiles = (newFiles) => {
    setFiles((prev) => [...prev, ...Array.from(newFiles)]);
  };

  const handleRemoveFile = (index) => {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  };

  const handleStopGeneration = () => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
      setIsGenerating(false);
      setIsThinking(false);

      setMessages((prev) => {
        const newMsgs = [...prev];
        if (newMsgs.length > 0 && newMsgs[newMsgs.length - 1].role === 'ai') {
          newMsgs[newMsgs.length - 1] = {
            ...newMsgs[newMsgs.length - 1],
            isStreaming: false,
            content: `${newMsgs[newMsgs.length - 1].content}\n\n*[Generation stopped]*`
          };
        }
        return newMsgs;
      });
    }
  };

  const handleEditMessage = (messageIndex, content) => {
    const newMessages = messages.slice(0, messageIndex);
    setMessages(newMessages);

    setChats((prev) => prev.map((c) =>
      c.id === activeChat ? { ...c, messages: newMessages, timestamp: Date.now() } : c
    ));

    setEditText(content);
  };

  return (
    <>
      <header className="header">
        <div className="hamburger-menu" onClick={toggleSidebar}>
          <Menu />
        </div>
        <img src="/cofonder.png" alt="COFUNDR" className="logo" />
      </header>

      {isMobile && (
        <Sidebar
          isOpen={isSidebarOpen}
          onClose={() => setIsSidebarOpen(false)}
          isMobile
          chats={chats}
          activeChat={activeChat}
          onSelectChat={handleSelectChat}
          onNewChat={handleNewChat}
        />
      )}

      <div className="chat-view active" style={{ opacity: 1, visibility: 'visible' }}>
        <div className="workspace-container">
          {!isMobile && (
            <Sidebar
              isMobile={false}
              chats={chats}
              activeChat={activeChat}
              onSelectChat={handleSelectChat}
              onNewChat={handleNewChat}
            />
          )}

          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', height: '100%', maxWidth: '100%' }}>
            <ChatArea
              messages={messages}
              isThinking={isThinking}
              onFileDrop={handleAddFiles}
              onEditMessage={handleEditMessage}
            />
            <InputDock
              onSendMessage={handleSendMessage}
              files={files}
              onAddFiles={handleAddFiles}
              onRemoveFile={handleRemoveFile}
              isGenerating={isGenerating}
              onStop={handleStopGeneration}
              editText={editText}
              onClearEditText={() => setEditText('')}
            />
          </div>
        </div>
      </div>
    </>
  );
}

export default App;
