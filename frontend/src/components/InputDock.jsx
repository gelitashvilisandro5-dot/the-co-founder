import { useState, useRef, useEffect } from 'react';
import { Paperclip, ArrowUp, X, File } from 'lucide-react';

const InputDock = ({ onSendMessage, files = [], onAddFiles, onRemoveFile, isGenerating = false, onStop, editText = '', onClearEditText }) => {
    const [text, setText] = useState('');
    const fileInputRef = useRef(null);
    const textAreaRef = useRef(null);

    // When editText is set from parent, update local text state
    useEffect(() => {
        if (!editText) return;

        const timer = window.setTimeout(() => {
            setText(editText);
            if (onClearEditText) onClearEditText();
            if (textAreaRef.current) {
                textAreaRef.current.focus();
                textAreaRef.current.style.height = 'auto';
                textAreaRef.current.style.height = textAreaRef.current.scrollHeight + 'px';
            }
        }, 0);

        return () => window.clearTimeout(timer);
    }, [editText, onClearEditText]);

    const handleSend = () => {
        if (!text.trim() && files.length === 0) return;
        onSendMessage(text);
        setText('');
        if (textAreaRef.current) textAreaRef.current.style.height = 'auto';
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (isGenerating) {
                return;
            }
            handleSend();
        }
    };

    const handleFileChange = (e) => {
        const selected = Array.from(e.target.files);
        if (onAddFiles) onAddFiles(selected);
        e.target.value = '';
    };

    const handleInput = (e) => {
        setText(e.target.value);
        e.target.style.height = 'auto';
        e.target.style.height = e.target.scrollHeight + 'px';
    };

    return (
        <div className="input-area">
            {/* File Previews */}
            {files.length > 0 && (
                <div className="file-previews">
                    {files.map((file, i) => (
                        <span key={i} className="file-chip">
                            <File size={14} /> {file.name.length > 20 ? `${file.name.substring(0, 20)}...` : file.name}
                            <X size={14} style={{ cursor: 'pointer' }} onClick={() => onRemoveFile(i)} />
                        </span>
                    ))}
                </div>
            )}

            <div className="input-container">
                <button className="icon-btn file-attach-btn" onClick={() => fileInputRef.current.click()} disabled={isGenerating}>
                    <Paperclip size={20} />
                </button>

                <input
                    type="file"
                    ref={fileInputRef}
                    multiple
                    style={{ display: 'none' }}
                    onChange={handleFileChange}
                    accept="image/*,video/*,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.pdf,.txt,.epub"
                />

                <textarea
                    ref={textAreaRef}
                    className="message-input"
                    placeholder="Message The Co-Founder..."
                    rows={1}
                    value={text}
                    onChange={handleInput}
                    onKeyDown={handleKeyDown}
                    disabled={isGenerating}
                />

                {isGenerating ? (
                    <button className="icon-btn stop-btn" onClick={onStop} title="Stop generation">
                        <div className="stop-icon"></div>
                    </button>
                ) : (
                    <button className="icon-btn send-btn" onClick={handleSend}>
                        <ArrowUp size={20} />
                    </button>
                )}
            </div>
        </div>
    );
};

export default InputDock;
