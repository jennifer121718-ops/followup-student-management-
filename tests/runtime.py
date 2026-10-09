from contextlib import contextmanager
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN') or shutil.which('php') or '/workspace/.followup-php/php'

@contextmanager
def php_server(router=None, public=None):
    with tempfile.TemporaryDirectory() as tmp:
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        private=Path(tmp)/'private'
        environment={**os.environ,'FOLLOWUP_PRIVATE_DIR':str(private)}
        log=open(Path(tmp)/'server.log','w+')
        process=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(public or ROOT/'web'),str(router or ROOT/'router.php')],cwd=ROOT,env=environment,stdout=log,stderr=log)
        base=f'http://127.0.0.1:{port}'
        try:
            for _ in range(100):
                try:
                    urllib.request.urlopen(base+'/',timeout=1).close();break
                except OSError:
                    if process.poll() is not None:
                        log.seek(0);raise RuntimeError(log.read())
                    time.sleep(.05)
            else:raise RuntimeError('PHP server did not start')
            yield base,private
        finally:
            process.terminate();process.wait(timeout=5);log.close()
