import { useState, useRef } from 'react';
import { Paperclip, ArrowUp, X, File } from 'lucide-react';

const InputDock = ({ onSendMessage }) => {
    const [text, setText] = useState('');
    const [files, setFiles] = useState([]);
    const fileInputRef = useRef(null);
    const textAreaRef = useRef(null);

    const handleSend = () => {
        if (!text.trim() && files.length === 0) return;
        onSendMessage(text, files);
        setText('');
        setFiles([]);
        if (textAreaRef.current) textAreaRef.current.style.height = 'auto'; // Reset height
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSend();
        }
    };

    const handleFileChange = (e) => {
        const selected = Array.from(e.target.files);
        // Filter accepted types if needed, similar to the HTML version
        setFiles(prev => [...prev, ...selected]);
        e.target.value = ''; // Reset input
    };

    const removeFile = (index) => {
        setFiles(prev => prev.filter((_, i) => i !== index));
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
                            <File size={14} /> {file.name.substring(0, 12)}...
                            <X size={14} style={{ cursor: 'pointer' }} onClick={() => removeFile(i)} />
                        </span>
                    ))}
                </div>
            )}

            <div className="input-container">
                <button className="icon-btn file-attach-btn" onClick={() => fileInputRef.current.click()}>
                    <Paperclip size={20} />
                </button>

                <input
                    type="file"
                    ref={fileInputRef}
                    multiple
                    style={{ display: 'none' }}
                    onChange={handleFileChange}
                    accept="image/*,video/*,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.pdf,.epub"
                />

                <textarea
                    ref={textAreaRef}
                    className="message-input"
                    placeholder="Message AI..."
                    rows={1}
                    value={text}
                    onChange={handleInput}
                    onKeyDown={handleKeyDown}
                />

                <button className="icon-btn send-btn" onClick={handleSend}>
                    <ArrowUp size={20} />
                </button>
            </div>
        </div>
    );
};

export default InputDock;
