// @ts-nocheck
import { useEffect, useRef, useState } from 'react';
import { Loader2, UploadCloud, Pencil } from 'lucide-react';
import TypewriterMessage from './TypewriterMessage';

const ChatArea = ({ messages, isThinking, onFileDrop, onEditMessage }) => {
    const scrollRef = useRef(null);
    const [isDragging, setIsDragging] = useState(false);
    const dragCounter = useRef(0);

    useEffect(() => {
        if (scrollRef.current) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        }
    }, [messages, isThinking]);

    const handleDragEnter = (e) => {
        e.preventDefault();
        e.stopPropagation();
        dragCounter.current++;
        // Safari compatible check: checking 'types' instead of 'items'
        if (e.dataTransfer.types && Array.from(e.dataTransfer.types).includes("Files")) {
            setIsDragging(true);
        }
    };

    const handleDragLeave = (e) => {
        e.preventDefault();
        e.stopPropagation();
        dragCounter.current--;
        if (dragCounter.current === 0) {
            setIsDragging(false);
        }
    };

    const handleDragOver = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };

    const handleDrop = (e) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragging(false);
        dragCounter.current = 0;
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            if (onFileDrop) {
                onFileDrop(e.dataTransfer.files);
            }
            e.dataTransfer.clearData();
        }
    };

    return (
        <div
            className="chat-container"
            onDragEnter={handleDragEnter}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            style={{ position: 'relative' }}
        >
            <div className="chat-history" ref={scrollRef}>
                {messages.length === 0 && !isThinking && (
                    <div style={{
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        justifyContent: 'center',
                        height: '100%',
                        color: 'var(--text-tertiary)',
                        fontSize: '15px',
                        textAlign: 'center',
                        padding: '40px'
                    }}>
                        <div style={{ fontSize: '32px', marginBottom: '16px', opacity: 0.5 }}>💬</div>
                        <div>Start a conversation</div>
                        <div style={{ fontSize: '13px', marginTop: '4px', opacity: 0.7 }}>
                            Ask anything. I'm brutally honest.
                        </div>
                    </div>
                )}

                {messages.map((msg, idx) => {
                    const isEmptyThinking = isThinking && idx === messages.length - 1 && msg.role === 'ai' && !msg.content;
                    return (
                        <div
                            key={idx}
                            className={`message-wrapper ${msg.role === 'user' ? 'user-wrapper' : 'ai-wrapper'}`}
                        >
                            <div className={`message ${msg.role === 'user' ? 'user-message' : 'ai-message'} fade-in`}>
                                {msg.role === 'ai' ? (
                                    isEmptyThinking ? (
                                        <div className="typing-dots">
                                            <span></span><span></span><span></span>
                                        </div>
                                    ) : (
                                        <TypewriterMessage
                                            text={msg.content || ""}
                                            isStreaming={msg.isStreaming === true}
                                            onComplete={() => {
                                                if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
                                            }}
                                        />
                                    )
                                ) : (
                                    <div className="user-content">
                                        {/* Image Attachments */}
                                        {msg.attachments && msg.attachments.length > 0 && (
                                            <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: msg.content ? '10px' : '0' }}>
                                                {msg.attachments.map((src, i) => (
                                                    <img
                                                        key={i}
                                                        src={src.data}
                                                        alt="attachment"
                                                        className="message-attachment"
                                                    />
                                                ))}
                                            </div>
                                        )}
                                        {/* Text */}
                                        {msg.content && <div>{msg.content}</div>}
                                        {/* Non-image files */}
                                        {msg.fileNames && (!msg.attachments || msg.attachments.length === 0) && (
                                            <div style={{ fontSize: '0.85em', opacity: 0.8, marginTop: '6px' }}>
                                                📎 {msg.fileNames.join(', ')}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                            {/* Edit button for user messages - appears on hover */}
                            {msg.role === 'user' && onEditMessage && (
                                <button
                                    className="edit-message-btn"
                                    onClick={() => onEditMessage(idx, msg.content)}
                                    title="Edit message"
                                >
                                    <Pencil size={14} />
                                </button>
                            )}
                        </div>
                    )
                })}
            </div>

            {/* Liquid Glass Drop Overlay (Moved to bottom for stacking context) */}
            {isDragging && (
                <div className="drop-overlay">
                    <UploadCloud size={48} />
                    <span>Drop file to upload</span>
                </div>
            )}
        </div>
    );
};

export default ChatArea;
