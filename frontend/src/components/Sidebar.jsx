import { X } from 'lucide-react';

const Sidebar = ({ isOpen, onClose, isMobile }) => {
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
                {/* Chat items will go here */}
                <div style={{ color: 'var(--text-tertiary)', fontSize: '13px', padding: '10px' }}>
                    No active chats
                </div>
            </div>
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
