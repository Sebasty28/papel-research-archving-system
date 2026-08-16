import os

directory = r"C:\xampp\htdocs\capstone"

modified_files = []
for root, dirs, files in os.walk(directory):
    if '.git' in root or 'vendor' in root:
        continue
    for file in files:
        if file.endswith('.php'):
            filepath = os.path.join(root, file)
            try:
                with open(filepath, 'r', encoding='utf-8') as f:
                    content = f.read()
                
                original_content = content
                
                # Fix the backslash issue
                content = content.replace("<?= function_exists(\\'csp_nonce\\') ? csp_nonce() : \\'\\' ?>", 
                                          "<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>")
                
                if content != original_content:
                    with open(filepath, 'w', encoding='utf-8') as f:
                        f.write(content)
                    modified_files.append(filepath)
            except Exception as e:
                print(f"Error processing {filepath}: {e}")

print(f"Fixed {len(modified_files)} files.")
