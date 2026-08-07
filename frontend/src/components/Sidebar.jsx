import { X, Plus, MessageSquare } from 'lucide-react';

const Sidebar = ({ isOpen, onClose, isMobile, chats = [], activeChat, onSelectChat, onNewChat }) => {
    // Generate time label
    const getTimeLabel = (timestamp) => {
        if (!timestamp) return '';
        const date = new Date(timestamp);
        const now = new Date();
        const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24));

        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return `${diffDays} days ago`;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    // Common content
    const content = (
        <>
            <div className={isMobile ? "mobile-sidebar-header" : ""}>
                <h2>Your Chats</h2>
                {isMobile && (
                    <button className="icon-btn close-sidebar-btn" onClick={onClose}>
                        <X size={20} />
                    </button>
                )}
            </div>

            <div className={isMobile ? "mobile-chat-list" : "chat-list"}>
                {chats.length === 0 ? (
                    <div style={{
                        color: 'var(--text-tertiary)',
                        fontSize: '13px',
                        padding: '16px 12px',
                        textAlign: 'center'
                    }}>
                        No conversations yet
                    </div>
                ) : (
                    chats.map((chat) => (
                        <div
                            key={chat.id}
                            className={`chat-item ${activeChat === chat.id ? 'active' : ''}`}
                            onClick={() => onSelectChat?.(chat.id)}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                <MessageSquare size={16} style={{ opacity: 0.5, flexShrink: 0 }} />
                                <div style={{ flex: 1, minWidth: 0 }}>
                                    <div className="chat-item-title">{chat.title}</div>
                                    <div className="chat-item-time">{getTimeLabel(chat.timestamp)}</div>
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>

            <button className="new-chat-btn" onClick={onNewChat}>
                <Plus size={18} />
                New Chat
            </button>
        </>
    );

    if (isMobile) {
        return (
            <>
                <div className={`mobile-sidebar-overlay ${isOpen ? 'active' : ''}`} onClick={onClose}></div>
                <div className={`mobile-sidebar ${isOpen ? 'active' : ''}`}>
                    {content}
                </div>
            </>
        );
    }

    return (
        <aside className="sidebar">
            {content}
        </aside>
    );
};

export default Sidebar;
