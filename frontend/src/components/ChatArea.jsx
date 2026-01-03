import { useEffect, useRef } from 'react';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const ChatArea = ({ messages, isThinking }) => {
    const scrollRef = useRef(null);

    useEffect(() => {
        if (scrollRef.current) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        }
    }, [messages, isThinking]);

    return (
        <div className="chat-container">
            <div className="chat-history" ref={scrollRef}>
                {messages.map((msg, idx) => (
                    <div
                        key={idx}
                        className={`message ${msg.role === 'user' ? 'user-message' : 'ai-message'}`}
                        style={{ alignSelf: msg.role === 'user' ? 'flex-end' : 'flex-start' }}
                    >
                        {msg.role === 'ai' ? (
                            <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(marked.parse(msg.content)) }} />
                        ) : (
                            msg.content
                        )}
                    </div>
                ))}

                {isThinking && (
                    <div className="message ai-message">
                        <i className="fas fa-circle-notch fa-spin"></i> Thinking...
                    </div>
                )}
            </div>
        </div>
    );
};

export default ChatArea;
