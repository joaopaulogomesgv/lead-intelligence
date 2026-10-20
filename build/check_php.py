import os
import re

plugin_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'lead-intelligence'))
print(f"Checking PHP files in: {plugin_dir}")

has_error = False

for root, dirs, files in os.walk(plugin_dir):
    for file in files:
        if not file.endswith('.php'):
            continue
        filepath = os.path.join(root, file)
        relpath = os.path.relpath(filepath, plugin_dir)
        
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            code = f.read()

        # Simple verification of open and close tags
        # Check PHP open tag
        if not code.strip().startswith('<?php'):
            print(f"[!] Warning: {relpath} does not start with <?php")
            
        # Parse brackets ignoring strings and comments
        stack = []
        in_string = False
        quote_char = ''
        in_comment_single = False
        in_comment_multi = False
        in_heredoc = False
        heredoc_id = ''
        
        lines = code.split('\n')
        for line_idx, line in enumerate(lines, 1):
            i = 0
            n = len(line)
            while i < n:
                c = line[i]
                
                if in_comment_single:
                    break
                    
                if in_comment_multi:
                    if c == '*' and i + 1 < n and line[i+1] == '/':
                        in_comment_multi = False
                        i += 2
                        continue
                    i += 1
                    continue
                    
                if in_string:
                    if c == '\\':
                        i += 2
                        continue
                    if c == quote_char:
                        in_string = False
                        quote_char = ''
                    i += 1
                    continue
                    
                if c == '/' and i + 1 < n and line[i+1] == '/':
                    in_comment_single = False
                    break
                if c == '#' and not (i + 1 < n and line[i+1] == '['): # not PHP 8 attribute #[
                    break
                if c == '/' and i + 1 < n and line[i+1] == '*':
                    in_comment_multi = True
                    i += 2
                    continue
                    
                if c in ('"', "'"):
                    in_string = True
                    quote_char = c
                    i += 1
                    continue
                    
                if c in '({[':
                    stack.append((c, line_idx, i+1))
                elif c in ')}]':
                    if not stack:
                        print(f"[X] Syntax ERROR in {relpath} L{line_idx}:{i+1} -> Unexpected closing '{c}'")
                        has_error = True
                        break
                    top, t_line, t_col = stack.pop()
                    match = {'(': ')', '{': '}', '[': ']'}[top]
                    if c != match:
                        print(f"[X] Syntax ERROR in {relpath} L{line_idx}:{i+1} -> Mismatched '{c}', expected '{match}' for '{top}' from L{t_line}:{t_col}")
                        has_error = True
                        break
                i += 1
                
        if stack:
            top, t_line, t_col = stack.pop()
            print(f"[X] Syntax ERROR in {relpath} -> Unclosed '{top}' from line {t_line}:{t_col}")
            has_error = True

if not has_error:
    print("[OK] All PHP files have matching brackets and basic syntax integrity.")
else:
    print("[FAIL] Found syntax errors!")
