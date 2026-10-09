"""Create an XServer upload package with a fresh, one-use setup key."""
import hashlib
import os
from pathlib import Path
import secrets
import zipfile

ROOT=Path(__file__).resolve().parents[1]
if __name__=='__main__':
    output=ROOT/'dist'
    output.mkdir(exist_ok=True)
    key=secrets.token_urlsafe(32)
    target=output/'followup-xserver.zip'
    # Package contains no real student database, accounts or existing credentials.
    with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as archive:
        for source in sorted((ROOT/'web').iterdir()):
            if source.is_file():
                archive.write(source,'public_html/followup/'+source.name)
        archive.writestr('followup-private/setup-hash.txt',hashlib.sha256(key.encode()).hexdigest())
        archive.writestr('followup-private/.htaccess','Require all denied\n')
        archive.writestr('初期設定キー.txt',key+'\n\n初回の講師登録にだけ使います。共有・公開フォルダへのアップロードはしないでください。\n')
        archive.write(ROOT/'XSERVER_SETUP.md','XSERVER_SETUP.md')
        archive.write(ROOT/'tools/reset_teacher.php','followup-private/reset_teacher.php')
    os.chmod(target,0o600)
    print('Created:',target)
