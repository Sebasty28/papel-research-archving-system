import os
import re

directory = r"C:\xampp\htdocs\capstone"

def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content

    # Hashes
    h_bs_css = 'sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN'
    h_bs_js = 'sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL'
    h_chart_js = 'sha384-1HhyA3vM8A9kly4oEhmEosYm05XwK92hTf/rJ6t+KjI0F0ZIKo2F07G16fF3+gS/'
    h_bs_icons = 'sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/Luz0x+O0E7kE2Eir3D'

    # 1. Add integrity to Bootstrap CSS
    content = re.sub(
        r'(<link[^>]*href="https://cdn\.jsdelivr\.net/npm/bootstrap@5\.3\.2/dist/css/bootstrap\.min\.css"[^>]*?)(/?>)',
        lambda m: m.group(1) + ('' if 'integrity' in m.group(1) else f' integrity="{h_bs_css}" crossorigin="anonymous"') + m.group(2),
        content
    )

    # 2. Add integrity to Bootstrap JS
    content = re.sub(
        r'(<script[^>]*src="https://cdn\.jsdelivr\.net/npm/bootstrap@5\.3\.2/dist/js/bootstrap\.bundle\.min\.js"[^>]*?)(>)',
        lambda m: m.group(1) + ('' if 'integrity' in m.group(1) else f' integrity="{h_bs_js}" crossorigin="anonymous"') + m.group(2),
        content
    )

    # 3. Add integrity to Chart.js
    content = re.sub(
        r'(<script[^>]*src="https://cdn\.jsdelivr\.net/npm/chart\.js@4\.4\.0/dist/chart\.umd\.min\.js"[^>]*?)(>)',
        lambda m: m.group(1) + ('' if 'integrity' in m.group(1) else f' integrity="{h_chart_js}" crossorigin="anonymous"') + m.group(2),
        content
    )

    # 4. Add integrity to Bootstrap Icons
    content = re.sub(
        r'(<link[^>]*href="https://cdn\.jsdelivr\.net/npm/bootstrap-icons@1\.11\.1/font/bootstrap-icons\.css"[^>]*?)(/?>)',
        lambda m: m.group(1) + ('' if 'integrity' in m.group(1) else f' integrity="{h_bs_icons}" crossorigin="anonymous"') + m.group(2),
        content
    )

    # 5. Inject nonce to <script> and <style>
    # Match <script ...> but ensure we don't duplicate nonce
    content = re.sub(
        r'<script(?![^>]*\bnonce=)([^>]*)>',
        r'<script nonce="<?= function_exists(\'csp_nonce\') ? csp_nonce() : \'\' ?>" \1>',
        content
    )

    content = re.sub(
        r'<style(?![^>]*\bnonce=)([^>]*)>',
        r'<style nonce="<?= function_exists(\'csp_nonce\') ? csp_nonce() : \'\' ?>" \1>',
        content
    )

    # Cleanup spaces
    content = content.replace(' >', '>')

    if content != original_content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        return True
    return False

modified_files = []
for root, dirs, files in os.walk(directory):
    if '.git' in root or 'vendor' in root:
        continue
    for file in files:
        if file.endswith('.php'):
            filepath = os.path.join(root, file)
            try:
                if process_file(filepath):
                    modified_files.append(filepath)
            except Exception as e:
                print(f"Error processing {filepath}: {e}")

print(f"Modified {len(modified_files)} files.")
