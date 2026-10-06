import os
import zipfile
import shutil

def package_plugin():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    project_dir = os.path.dirname(script_dir)
    source_dir = os.path.join(project_dir, 'lead-intelligence')
    
    zip_filename = 'lead-intelligence.zip'
    output_local = os.path.join(project_dir, zip_filename)

    # Identifica pasta Desktop do usuário (incluindo OneDrive se aplicável)
    user_home = os.path.expanduser('~')
    desktop_candidates = [
        os.path.join(user_home, 'OneDrive', 'Área de Trabalho'),
        os.path.join(user_home, 'OneDrive', 'Desktop'),
        os.path.join(user_home, 'Área de Trabalho'),
        os.path.join(user_home, 'Desktop'),
    ]

    target_desktop = None
    for cand in desktop_candidates:
        if os.path.exists(cand):
            target_desktop = cand
            break

    # Extrai versão atual do plugin
    version = 'desconhecida'
    main_file = os.path.join(source_dir, 'lead-intelligence.php')
    if os.path.exists(main_file):
        with open(main_file, 'r', encoding='utf-8') as f:
            for line in f:
                if 'LEAD_INTELLIGENCE_VERSION' in line and 'define' in line:
                    parts = line.split(',')
                    if len(parts) > 1:
                        version = parts[1].strip().strip("');\"")
                        break

    print(f"Compactando plugin versão v{version} de: {source_dir}")

    # Cria o arquivo ZIP garantindo separadores '/' (estilo Unix)
    with zipfile.ZipFile(output_local, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(source_dir):
            for file in files:
                file_path = os.path.join(root, file)
                rel_path = os.path.relpath(file_path, project_dir)
                
                # Regra estrita: sempre usar forward slashes '/' para compatibilidade com Linux
                arcname = rel_path.replace('\\', '/')
                zipf.write(file_path, arcname)
                print(f" + Adicionado: {arcname}")

    print(f"\n[OK] Arquivo gerado com sucesso: {output_local}")

    if target_desktop:
        output_desktop = os.path.join(target_desktop, zip_filename)
        shutil.copy2(output_local, output_desktop)
        print(f"[OK] Cópia enviada para a Área de Trabalho: {output_desktop}")
    else:
        print("[!] Diretório Desktop não localizado automaticamente.")

if __name__ == '__main__':
    package_plugin()
