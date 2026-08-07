// @ts-nocheck
import { useEffect, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';

const CHARS_PER_SECOND = 140;

const TypewriterMessage = ({ text, isStreaming = false, onComplete }) => {
    const safeText = text || '';
    const [displayedText, setDisplayedText] = useState(safeText);

    const targetTextRef = useRef(safeText);
    const displayedTextRef = useRef(safeText);
    const rafRef = useRef(null);
    const lastTsRef = useRef(0);
    const carryMsRef = useRef(0);
    const completionKeyRef = useRef('');

    useEffect(() => {
        displayedTextRef.current = displayedText;
    }, [displayedText]);

    useEffect(() => {
        targetTextRef.current = safeText;

        // Reset quickly when the message shrinks/restarts.
        if (safeText.length < displayedTextRef.current.length) {
            if (rafRef.current !== null) {
                window.cancelAnimationFrame(rafRef.current);
                rafRef.current = null;
            }
            lastTsRef.current = 0;
            carryMsRef.current = 0;
            window.requestAnimationFrame(() => setDisplayedText(safeText));
            return;
        }

        // If new chars arrived, animate until we catch up.
        if (safeText.length > displayedTextRef.current.length && rafRef.current === null) {
            const step = (ts) => {
                if (!lastTsRef.current) lastTsRef.current = ts;
                const delta = ts - lastTsRef.current;
                lastTsRef.current = ts;
                carryMsRef.current += delta;

                const msPerChar = 1000 / CHARS_PER_SECOND;
                const addCount = Math.floor(carryMsRef.current / msPerChar);

                if (addCount > 0) {
                    carryMsRef.current = carryMsRef.current % msPerChar;
                    setDisplayedText((prev) => {
                        const target = targetTextRef.current;
                        if (prev.length >= target.length) return prev;
                        const nextLen = Math.min(target.length, prev.length + addCount);
                        return target.slice(0, nextLen);
                    });
                }

                if (displayedTextRef.current.length < targetTextRef.current.length) {
                    rafRef.current = window.requestAnimationFrame(step);
                } else {
                    rafRef.current = null;
                    lastTsRef.current = 0;
                    carryMsRef.current = 0;
                }
            };

            rafRef.current = window.requestAnimationFrame(step);
        }
    }, [safeText]);

    useEffect(() => {
        return () => {
            if (rafRef.current !== null) {
                window.cancelAnimationFrame(rafRef.current);
                rafRef.current = null;
            }
        };
    }, []);

    useEffect(() => {
        const isDone = !isStreaming && displayedText === safeText;
        if (!isDone || !onComplete) return;

        const key = `${safeText.length}:${safeText.slice(-24)}`;
        if (completionKeyRef.current === key) return;
        completionKeyRef.current = key;
        onComplete();
    }, [isStreaming, displayedText, safeText, onComplete]);

    const showCursor = isStreaming || displayedText.length < safeText.length;

    return (
        <div className="markdown-content">
            <ReactMarkdown
                remarkPlugins={[remarkMath]}
                rehypePlugins={[rehypeKatex]}
            >
                {displayedText}
            </ReactMarkdown>
            {showCursor && <span className="typing-cursor">▋</span>}
        </div>
    );
};

export default TypewriterMessage;
