"""Run against the disposable Apache test container only, never a live school site."""
import json
import http.client
import sqlite3
import tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright,expect

BASE='http://127.0.0.1:18001/followup/'
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1440,'height':1000});errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(BASE)
    expect(page.locator('#setup')).to_be_visible()
    for name,value in {'name':'検証講師','email':'teacher@example.test','password':'test-password-123','confirmation':'test-password-123','setup_key':'test-activation-key'}.items():
        page.locator(f'#setup [name={name}]').fill(value)
    page.get_by_role('button',name='アカウントを作成して開始').click()
    expect(page.locator('h1')).to_have_text('生徒ダッシュボード')
    cookies=page.context.cookies();assert any(c['secure'] and c['httpOnly'] and c['path']=='/followup' for c in cookies)
    for n in range(1,15):
        status=page.evaluate('''async d=>(await fetch('./api/students',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(d)})).status''',{'name':f'検証生徒{n:02d}','email':f'student{n}@example.test','password':'test-password-123'})
        assert status==200
    page.reload();expect(page.locator('#rows tr')).to_have_count(14)
    with page.expect_download() as download:
        page.get_by_role('button',name='この月の一覧をCSV保存').click()
    text=Path(download.value.path()).read_text(encoding='utf-8-sig');assert '検証生徒14' in text
    with page.expect_download() as download:
        page.get_by_role('button',name='全データをバックアップ').click()
    with sqlite3.connect(download.value.path()) as db:
        assert db.execute('SELECT count(*) FROM users').fetchone()[0]==15
    page.screenshot(path='/tmp/followup-dashboard.png',full_page=True)
    assert page.request.get(BASE+'settings.php').status==403
    assert page.evaluate("async()=> (await fetch('./api/reports')).status")==200
    assert page.request.get(BASE+'../../private/students.sqlite3').status==404
    assert not errors,errors
    browser.close()
print('PASS: Apache rewrite, /followup/ deployment, activation, Secure cookies, 14 students, CSV and private backup downloads, settings denied')
