"""Uses temporary fictional data and private mail previews; sends no real email."""
import json,re
from playwright.sync_api import sync_playwright,expect
from runtime import php_server

with php_server() as (base,private), sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    teacher=browser.new_page();errors=[];teacher.on('pageerror',lambda e:errors.append(str(e)))
    teacher.goto(base)
    for name,value in {'name':'講師','email':'teacher@example.test','password':'Teacher-pass1','confirmation':'Teacher-pass1'}.items():teacher.locator(f'#setup [name={name}]').fill(value)
    teacher.get_by_role('button',name='アカウントを作成して開始').click();expect(teacher.locator('h1')).to_have_text('生徒ダッシュボード')
    assert teacher.evaluate("async()=> (await fetch('/api/students',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({name:'生徒A',email:'a@example.test',password:'Student-pass1'})})).status")==200
    teacher.reload();expect(teacher.locator('#rows')).to_contain_text('生徒A')
    student=browser.new_page();student.on('pageerror',lambda e:errors.append(str(e)));student.goto(base)
    password=student.locator('#login [name=password]');password.fill('Student-pass1')
    toggle=password.locator('xpath=following-sibling::button[1]');toggle.click();expect(password).to_have_attribute('type','text');toggle.click();expect(password).to_have_attribute('type','password')
    student.get_by_role('button',name='パスワードを忘れた方').click()
    student.locator('#forgot-email').fill('a@example.test');student.get_by_role('button',name='再設定メールを依頼').click();expect(student.locator('#forgot-result')).to_contain_text('登録されている場合')
    preview=json.loads(next((private/'mail-preview').glob('reset-*.json')).read_text());token=re.search(r'#reset=([a-f0-9]{64})',preview['message']).group(1)
    student.goto(base+'/#reset='+token);expect(student.locator('#recover')).to_be_visible();assert student.evaluate('location.hash')==''
    student.locator('#recover [name=password]').fill('Reset-pass1');student.locator('#recover [name=confirmation]').fill('Reset-pass1');student.get_by_role('button',name='新しいパスワードを設定').click();expect(student.locator('#recover-result')).to_contain_text('パスワードを変更しました')
    student.get_by_role('button',name='ログインに戻る').click();student.locator('#login [name=email]').fill('a@example.test');student.locator('#login [name=password]').fill('Reset-pass1');student.get_by_role('button',name='ログイン',exact=True).click();expect(student.locator('h1')).to_have_text('生徒A')
    student.get_by_role('button',name='ログアウト').click();expect(student.locator('#login')).to_be_visible()
    statuses=student.evaluate("async()=>{let codes=[];for(let i=0;i<10;i++){let r=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:'a@example.test',password:'Wrong-pass1'})});codes.push(r.status);await r.json()}return codes}")
    assert statuses==[401]*9+[423]
    teacher.reload();expect(teacher.locator('#rows')).to_contain_text('ログインロック中');teacher.locator('#rows').get_by_role('button',name='生徒A',exact=True).click();expect(teacher.locator('h1')).to_have_text('生徒A')
    teacher.on('dialog',lambda dialog:dialog.accept());teacher.get_by_role('button',name='ログインのロックを解除').click();expect(teacher.locator('#unlock')).to_have_count(0)
    student.locator('#login [name=email]').fill('a@example.test');student.locator('#login [name=password]').fill('Reset-pass1');student.get_by_role('button',name='ログイン',exact=True).click();expect(student.locator('h1')).to_have_text('生徒A')
    assert not errors,errors
    browser.close()
print('PASS: password visibility, forgot-password email flow, one-use reset screen, name-only titles, lock at 10 and teacher unlock')
