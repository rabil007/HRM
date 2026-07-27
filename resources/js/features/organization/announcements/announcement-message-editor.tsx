import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Heading2,
    Italic,
    Link2,
    List,
    ListOrdered,
    Quote,
    Redo2,
    Underline as UnderlineIcon,
    Undo2,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

type AnnouncementMessageEditorProps = {
    id: string;
    value: string;
    onChange: (value: string) => void;
    invalid?: boolean;
};

type ToolbarAction = {
    label: string;
    Icon: typeof Bold;
    active: boolean;
    disabled: boolean;
    run: () => void;
};

function normalizeLinkUrl(url: string): string | null {
    const trimmed = url.trim();

    if (trimmed === '') {
        return null;
    }

    if (
        trimmed.startsWith('#') ||
        trimmed.startsWith('/') ||
        trimmed.startsWith('mailto:') ||
        trimmed.startsWith('tel:')
    ) {
        return trimmed;
    }

    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }

    return `https://${trimmed}`;
}

export function AnnouncementMessageEditor({
    id,
    value,
    onChange,
    invalid = false,
}: AnnouncementMessageEditorProps) {
    const lastEmittedValueRef = useRef(value);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: {
                    levels: [2, 3],
                },
            }),
            Underline,
            Link.configure({
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                HTMLAttributes: {
                    class: 'text-primary underline underline-offset-2',
                    rel: 'noopener noreferrer',
                    target: '_blank',
                },
            }),
        ],
        content: value,
        immediatelyRender: false,
        editorProps: {
            attributes: {
                id,
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-label': 'Message body',
                class: 'min-h-52 px-4 py-3 text-sm leading-6 outline-none',
            },
        },
        onUpdate: ({ editor: updatedEditor }) => {
            const nextValue = updatedEditor.isEmpty
                ? ''
                : updatedEditor.getHTML();

            lastEmittedValueRef.current = nextValue;
            onChange(nextValue);
        },
    });

    useEffect(() => {
        if (!editor || value === lastEmittedValueRef.current) {
            return;
        }

        if (editor.getHTML() !== value) {
            editor.commands.setContent(value, { emitUpdate: false });
        }

        lastEmittedValueRef.current = value;
    }, [editor, value]);

    const state = useEditorState({
        editor,
        selector: ({ editor: currentEditor }) => {
            if (!currentEditor) {
                return null;
            }

            return {
                bold: currentEditor.isActive('bold'),
                italic: currentEditor.isActive('italic'),
                underline: currentEditor.isActive('underline'),
                link: currentEditor.isActive('link'),
                heading: currentEditor.isActive('heading', { level: 2 }),
                bulletList: currentEditor.isActive('bulletList'),
                orderedList: currentEditor.isActive('orderedList'),
                blockquote: currentEditor.isActive('blockquote'),
                canUndo: currentEditor.can().chain().focus().undo().run(),
                canRedo: currentEditor.can().chain().focus().redo().run(),
            };
        },
    });

    const toggleLink = () => {
        if (!editor) {
            return;
        }

        if (editor.isActive('link')) {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();

            return;
        }

        const previousUrl = editor.getAttributes('link').href as
            | string
            | undefined;
        const entered = window.prompt(
            'Enter link URL',
            previousUrl ?? 'https://',
        );

        if (entered === null) {
            return;
        }

        const href = normalizeLinkUrl(entered);

        if (href === null) {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();

            return;
        }

        editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
    };

    const actions: ToolbarAction[] = editor
        ? [
              {
                  label: 'Bold',
                  Icon: Bold,
                  active: state?.bold ?? false,
                  disabled: !editor.can().chain().focus().toggleBold().run(),
                  run: () => editor.chain().focus().toggleBold().run(),
              },
              {
                  label: 'Italic',
                  Icon: Italic,
                  active: state?.italic ?? false,
                  disabled: !editor.can().chain().focus().toggleItalic().run(),
                  run: () => editor.chain().focus().toggleItalic().run(),
              },
              {
                  label: 'Underline',
                  Icon: UnderlineIcon,
                  active: state?.underline ?? false,
                  disabled: !editor
                      .can()
                      .chain()
                      .focus()
                      .toggleUnderline()
                      .run(),
                  run: () => editor.chain().focus().toggleUnderline().run(),
              },
              {
                  label: 'Link',
                  Icon: Link2,
                  active: state?.link ?? false,
                  disabled: false,
                  run: toggleLink,
              },
              {
                  label: 'Heading',
                  Icon: Heading2,
                  active: state?.heading ?? false,
                  disabled: !editor
                      .can()
                      .chain()
                      .focus()
                      .toggleHeading({ level: 2 })
                      .run(),
                  run: () =>
                      editor.chain().focus().toggleHeading({ level: 2 }).run(),
              },
              {
                  label: 'Bullet list',
                  Icon: List,
                  active: state?.bulletList ?? false,
                  disabled: !editor
                      .can()
                      .chain()
                      .focus()
                      .toggleBulletList()
                      .run(),
                  run: () => editor.chain().focus().toggleBulletList().run(),
              },
              {
                  label: 'Numbered list',
                  Icon: ListOrdered,
                  active: state?.orderedList ?? false,
                  disabled: !editor
                      .can()
                      .chain()
                      .focus()
                      .toggleOrderedList()
                      .run(),
                  run: () => editor.chain().focus().toggleOrderedList().run(),
              },
              {
                  label: 'Quote',
                  Icon: Quote,
                  active: state?.blockquote ?? false,
                  disabled: !editor
                      .can()
                      .chain()
                      .focus()
                      .toggleBlockquote()
                      .run(),
                  run: () => editor.chain().focus().toggleBlockquote().run(),
              },
              {
                  label: 'Undo',
                  Icon: Undo2,
                  active: false,
                  disabled: !(state?.canUndo ?? false),
                  run: () => editor.chain().focus().undo().run(),
              },
              {
                  label: 'Redo',
                  Icon: Redo2,
                  active: false,
                  disabled: !(state?.canRedo ?? false),
                  run: () => editor.chain().focus().redo().run(),
              },
          ]
        : [];

    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-input bg-background shadow-xs transition-[color,box-shadow]',
                'focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50',
                invalid &&
                    'border-destructive ring-destructive/20 focus-within:border-destructive focus-within:ring-destructive/20',
            )}
        >
            <div
                className="flex flex-wrap items-center gap-1 border-b border-border/70 bg-muted/30 p-2"
                role="toolbar"
                aria-label="Message formatting"
            >
                {actions.map(({ label, Icon, active, disabled, run }) => (
                    <button
                        key={label}
                        type="button"
                        title={label}
                        aria-label={label}
                        aria-pressed={active}
                        disabled={disabled}
                        onPointerDown={(event) => event.preventDefault()}
                        onClick={run}
                        className={cn(
                            'flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors',
                            'hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40',
                            active && 'bg-accent text-accent-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                    </button>
                ))}
            </div>
            <div className="announcement-editor">
                {editor ? (
                    <EditorContent editor={editor} />
                ) : (
                    <div className="min-h-52 animate-pulse bg-muted/20" />
                )}
            </div>
        </div>
    );
}
