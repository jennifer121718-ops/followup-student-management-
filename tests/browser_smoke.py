"""End-to-end checks; requires Playwright and Chromium, uses temporary fictional data only."""
import sys
sys.path.insert(0, str(__import__('pathlib').Path(__file__).resolve().parents[1]))
import tempfile
import threading
from pathlib import Path
from http.server import ThreadingHTTPServer
from playwright.sync_api import sync_playwright, expect
from runtime import php_server
import sqlite3


def run():
    with php_server() as (base,private):
        try:
            with sync_playwright() as p:
                browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
                teacher = browser.new_page(viewport={'width':1440,'height':1000})
                errors = []
                teacher.on('pageerror', lambda e: errors.append(str(e)))
                teacher.goto(base)
                expect(teacher.locator('h1')).to_have_text('はじめての設定')
                for name, value in {'name':'テスト講師','email':'teacher@example.test','password':'Teacher-test-123','confirmation':'Teacher-test-123'}.items():
                    teacher.locator(f'#setup [name={name}]').fill(value)
                teacher.get_by_role('button',name='アカウントを作成して開始').click()
                expect(teacher.locator('h1')).to_have_text('生徒ダッシュボード')
                teacher.get_by_text('生徒を追加する',exact=True).click()
                for name,value in {'name':'テスト生徒01','email':'student1@example.test','password':'Student-test-123','prefecture':'東京都','cohort':'第1期','start_date':'2026-09-01'}.items():
                    teacher.locator(f'#add [name={name}]').fill(value)
                teacher.get_by_role('button',name='生徒を登録',exact=True).click()
                expect(teacher.locator('#rows')).to_contain_text('テスト生徒01')
                for n in range(2,15):
                    response = teacher.evaluate('''async d=>{let r=await fetch('/api/students',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(d)});return r.status}''',{'name':f'テスト生徒{n:02d}','email':f'student{n}@example.test','password':'Student-test-123'})
                    assert response == 200
                teacher.reload()
                expect(teacher.locator('#rows tr')).to_have_count(14)
                teacher.screenshot(path='/tmp/followup-dashboard.png', full_page=True)
                student = browser.new_page(viewport={'width':390,'height':844})
                student.on('pageerror',lambda e:errors.append(str(e)))
                student.goto(base)
                student.locator('#login [name=email]').fill('student1@example.test')
                student.locator('#login [name=password]').fill('Student-test-123')
                student.get_by_role('button',name='ログイン',exact=True).click()
                expect(student.locator('h1')).to_contain_text('テスト生徒01')
                student.locator('#month').fill('2026-09')
                for name,value in {'sales':'100000','gross_profit':'40000','net_profit':'20000','target':'80000','units':'20','next_target':'120000','next_goal':'販売導線を改善','activities':'仕入れ改善'}.items():
                    student.locator(f'#report [name={name}]').fill(value)
                student.get_by_role('button',name='月報を提出',exact=True).click()
                expect(student.locator('#monthly-history tbody tr')).to_have_count(1)
                expect(student.locator('#monthly-history')).to_contain_text('125.0%')
                student.locator('#month').fill('2026-10')
                expect(student.locator('#report [name=target]')).to_have_value('120000')
                expect(student.locator('#report [name=current_goal]')).to_have_value('販売導線を改善')
                student.locator('#report [name=sales]').fill('150000')
                student.locator('#report [name=net_profit]').fill('-5000')
                student.get_by_role('button',name='下書き保存',exact=True).click()
                expect(student.locator('#monthly-history tbody tr')).to_have_count(1)
                student.get_by_role('button',name='月報を提出',exact=True).click()
                expect(student.locator('#monthly-history tbody tr')).to_have_count(2)
                expect(student.locator('#monthly-history')).to_contain_text('50.0%')
                expect(student.locator('.negative')).to_have_count(1)
                student.reload()
                expect(student.locator('#report [name=sales]')).to_have_value('150000')
                teacher.reload()
                teacher.locator('#month').fill('2026-10')
                teacher.locator('#rows').get_by_role('button',name='テスト生徒01',exact=True).click()
                teacher.locator('#feedback [name=comment]').fill('来月は経費を見直しましょう。')
                teacher.locator('#feedback [name=status]').select_option('フォロー必要')
                teacher.get_by_role('button',name='コメントを保存',exact=True).click()
                expect(teacher.locator('#feedback [name=comment]')).to_have_value('来月は経費を見直しましょう。')
                student.reload()
                expect(student.locator('.comment')).to_have_text('来月は経費を見直しましょう。')
                assert not student.locator('#feedback').count()
                student.locator('#detail-week').fill('2026-10-09')
                expect(student.locator('#detail-week')).to_have_value('2026-10-05')
                student.locator('#weekly-report [name=activities]').fill('今週の仕入れ改善')
                student.locator('#weekly-report [name=challenges]').fill('出品について相談したい')
                student.locator('#weekly-report [name=condition]').select_option('相談したい')
                student.get_by_role('button',name='週報を下書き保存',exact=True).click()
                expect(student.locator('#weekly-detail')).to_contain_text('下書き')
                student.get_by_role('button',name='週報を提出',exact=True).click()
                expect(student.locator('#weekly-detail table')).to_contain_text('提出済み')
                student.locator('#weekly-report [name=activities]').fill('今週の仕入れ改善・追記')
                student.get_by_role('button',name='週報を提出',exact=True).click()
                expect(student.locator('#weekly-report [name=activities]')).to_have_value('今週の仕入れ改善・追記')
                teacher.reload()
                expect(teacher.locator('#weekly-dashboard')).to_contain_text('相談したい')
                teacher.locator('#rows').get_by_role('button',name='テスト生徒01',exact=True).click()
                teacher.locator('#weekly-feedback [name=comment]').fill('週報を確認しました。相談しましょう。')
                teacher.get_by_role('button',name='週報コメントを保存',exact=True).click()
                expect(teacher.locator('#weekly-feedback [name=comment]')).to_have_value('週報を確認しました。相談しましょう。')
                student.reload()
                expect(student.locator('.weekly-comment')).to_have_text('週報を確認しました。相談しましょう。')
                student.locator('#detail-week').fill('2025-01-08')
                expect(student.locator('#detail-week')).to_have_value('2025-01-06')
                student.locator('#weekly-report [name=activities]').fill('過去の週の振り返り')
                student.get_by_role('button',name='週報を提出',exact=True).click()
                expect(student.locator('#weekly-detail table tbody tr')).to_have_count(2)
                assert student.evaluate("async()=> (await fetch('/api/weekly?student_id=3')).status") == 403
                # Deliberately request someone else's report from the authenticated browser.
                assert student.evaluate("async()=> (await fetch('/api/reports?student_id=3')).status") == 403
                other = browser.new_page()
                other.goto(base)
                other.locator('#login [name=email]').fill('student2@example.test')
                other.locator('#login [name=password]').fill('Student-test-123')
                other.get_by_role('button',name='ログイン',exact=True).click()
                expect(other.locator('h1')).to_contain_text('テスト生徒02')
                assert other.evaluate("async()=> (await (await fetch('/api/reports')).json()).length") == 0
                assert other.evaluate("async()=> (await (await fetch('/api/weekly')).json()).length") == 0
                teacher.on('dialog',lambda dialog: dialog.accept())
                teacher.get_by_role('button',name='卒業扱いにする',exact=True).click()
                expect(teacher.get_by_role('button',name='在籍に戻す')).to_be_visible()
                student.reload()
                expect(student.locator('#login')).to_be_visible()
                teacher.get_by_role('button',name='在籍に戻す',exact=True).click()
                expect(teacher.get_by_role('button',name='卒業扱いにする')).to_be_visible()
                # The old session stays invalid even after reactivation.
                assert student.evaluate("async()=> (await fetch('/api/reports')).status") == 401
                assert not errors, errors
                browser.close()
                with sqlite3.connect(private/'students.sqlite3') as db:
                    assert db.execute('SELECT count(*) FROM reports WHERE submitted=1').fetchone()[0] == 2
                print('PASS: setup, 14 students, login, draft/submit, persistence, goals, charts, comments, isolation, graduation and mobile viewport')
        finally:
            pass

if __name__ == '__main__':
    run()
