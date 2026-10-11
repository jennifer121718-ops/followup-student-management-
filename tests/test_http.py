import hashlib
import http.client
import json
from pathlib import Path
import sqlite3
import unittest
from urllib.parse import urlparse
from runtime import php_server, ROOT

class HTTPTests(unittest.TestCase):
    def setUp(self):
        self.context=php_server();self.base,self.private=self.context.__enter__()
        self.host=urlparse(self.base).netloc
        self.assertEqual(self.request('/api/setup',{'name':'講師','email':'teacher@example.test','password':'Test-password-123'})[0],200)
        self.teacher=self.login('teacher@example.test')
        for name,email in [('生徒A','a@example.test'),('生徒B','b@example.test')]:
            self.assertEqual(self.request('/api/students',{'name':name,'email':email,'password':'Test-password-123'},self.teacher)[0],200)

    def tearDown(self):
        self.context.__exit__(None,None,None)

    def request(self,path,data=None,auth=None,csrf=True,headers=None):
        connection=http.client.HTTPConnection(self.host)
        h={'Content-Type':'application/json',**(headers or {})}
        if auth:
            h['Cookie']=auth[0]
            if csrf:h['X-CSRF-Token']=auth[1]
        connection.request('POST' if data is not None else 'GET',path,json.dumps(data) if data is not None else None,h)
        r=connection.getresponse();body=r.read();cookie=r.getheader('Set-Cookie')
        value=json.loads(body) if 'application/json' in r.getheader('Content-Type','') else body
        result=(r.status,value,cookie);connection.close();return result

    def login(self,email,password='Test-password-123'):
        code,_,cookie=self.request('/api/login',{'email':email,'password':password})
        self.assertEqual(code,200)
        cookie=cookie.split(';')[0]
        _,me,_=self.request('/api/me',auth=(cookie,''))
        return cookie,me['csrf']

    def test_isolation(self):
        a=self.login('a@example.test');b=self.login('b@example.test')
        payload={'student_id':2,'month':'2026-10','sales':100000,'net_profit':20000,'target':80000,'submitted':True}
        self.assertEqual(self.request('/api/report',payload,a)[0],200)
        self.assertEqual(self.request('/api/report',{**payload,'student_id':3},a)[0],403)
        self.assertEqual(self.request('/api/reports?student_id=2',auth=b)[0],403)
        self.assertEqual(self.request('/api/reports',auth=b)[1],[])
        self.assertEqual(self.request('/api/students',auth=a)[0],403)
        self.assertEqual(self.request('/api/students',{'name':'evil','email':'evil@example.test','password':'Test-password-123'},a)[0],403)
        feedback={'student_id':2,'month':'2026-10','comment':'良い進捗です','status':'順調'}
        self.assertEqual(self.request('/api/feedback',feedback,a)[0],403)
        self.assertEqual(self.request('/api/feedback',feedback,self.teacher)[0],200)
        self.assertEqual(self.request('/api/report',{**payload,'comment':'改ざん','status':'フォロー必要'},a)[0],200)
        row=self.request('/api/reports',auth=a)[1][0]
        self.assertEqual(row['comment'],feedback['comment']);self.assertEqual(row['status'],'順調')
        self.assertEqual(self.request('/api/backup',{},a)[0],403)
        self.assertEqual(self.request('/api/reports')[0],401)
        self.assertEqual(self.request('/api/me',auth=a)[1]['user']['id'],2)
        self.assertNotIn('password',self.request('/api/me',auth=a)[1]['user'])

    def test_validation_and_csrf(self):
        a=self.login('a@example.test')
        self.assertEqual(self.request('/api/report',{'month':'2026-10'},a,csrf=False)[0],403)
        for payload in [{'month':'bad'},{'month':'2026-13'},{'month':'2026-10','sales':-1},{'month':'2026-10','sales':'NaN'},{'month':'2026-10','units':1.2},{'month':'2026-10','submitted':'false'}]:
            self.assertEqual(self.request('/api/report',payload,a)[0],400)
        self.assertEqual(self.request('/api/reports',auth=a)[1],[])
        self.assertEqual(self.request('/api/report',{'month':'2026-10'},a,headers={'Origin':'https://evil.example'})[0],403)
        self.assertEqual(self.request('/api/setup',{'name':'evil','email':'evil@example.test','password':'Test-password-123'})[0],403)

    def test_profile_password_and_graduation(self):
        a=self.login('a@example.test')
        profile={'id':2,'name':'生徒A改','email':'new-a@example.test','prefecture':'東京都','cohort':'1期','start_date':'2026-10-01'}
        self.assertEqual(self.request('/api/student/profile',profile,a)[0],403)
        self.assertEqual(self.request('/api/student/profile',profile,self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[1]['user']['name'],'生徒A改')
        self.assertEqual(self.request('/api/student/password',{'id':2,'password':'Replacement-password-123'},a)[0],403)
        self.assertEqual(self.request('/api/student/password',{'id':2,'password':'Replacement-password-123'},self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)
        a=self.login('new-a@example.test','Replacement-password-123')
        self.assertEqual(self.request('/api/password',{'current':'Replacement-password-123','password':'New-password-123'},a)[0],200)
        self.assertEqual(self.request('/api/student/update',{'id':2,'active':False},self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)
        self.assertEqual(self.request('/api/student/update',{'id':2,'active':True},self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)
        a=self.login('new-a@example.test','New-password-123')
        self.assertEqual(self.request('/api/logout',{},a)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)

    def test_password_complexity(self):
        data={'name':'条件テスト','email':'complex@example.test'}
        for password in ['Abc1234','abcdefgh','ABCDEFGH','12345678','abcd1234','ABCD1234','Abcdefgh','あいうえおかきく']:
            self.assertEqual(self.request('/api/students',{**data,'password':password},self.teacher)[0],400)
        self.assertEqual(self.request('/api/students',{**data,'password':'Abcd1234'},self.teacher)[0],200)
        student=self.login(data['email'],'Abcd1234')
        self.assertEqual(self.request('/api/password',{'current':'Abcd1234','password':'abcd1234'},student)[0],400)
        self.assertEqual(self.request('/api/password',{'current':'Abcd1234','password':'Xy123456'},student)[0],200)
        student=self.login(data['email'],'Xy123456')
        student_id=self.request('/api/me',auth=student)[1]['user']['id']
        self.assertEqual(self.request('/api/student/password',{'id':student_id,'password':'12345678'},self.teacher)[0],400)
        self.assertEqual(self.request('/api/student/password',{'id':student_id,'password':'Zz987654'},self.teacher)[0],200)
        self.login(data['email'],'Zz987654')

    def test_weekly_reports_and_isolation(self):
        a=self.login('a@example.test');b=self.login('b@example.test')
        monthly={'month':'2026-10','sales':123456,'submitted':True}
        self.assertEqual(self.request('/api/report',monthly,a)[0],200)
        with sqlite3.connect(self.private/'students.sqlite3') as legacy:
            legacy.execute('DROP TABLE weekly_reports')
        payload={'week_start':'2026-10-05','activities':'商品選定','challenges':'仕入れの相談','next_actions':'出品','condition':'相談したい','submitted':False}
        self.assertEqual(self.request('/api/weekly',payload,a)[0],200)
        self.assertEqual(self.request('/api/weekly',auth=a)[1][0]['submitted'],0)
        self.assertEqual(self.request('/api/weekly',{**payload,'submitted':True},a)[0],200)
        self.assertEqual(self.request('/api/weekly',{**payload,'activities':'追記','submitted':True},a)[0],200)
        row=self.request('/api/weekly',auth=a)[1][0]
        self.assertEqual(row['activities'],'追記');self.assertEqual(row['submitted'],1)
        self.assertEqual(self.request('/api/weekly',auth=b)[1],[])
        self.assertEqual(self.request('/api/weekly?student_id=2',auth=b)[0],403)
        self.assertEqual(self.request('/api/weekly',{**payload,'student_id':3},a)[0],403)
        self.assertEqual(self.request('/api/weekly',payload,a,csrf=False)[0],403)
        self.assertEqual(self.request('/api/weekly',{**payload,'week_start':'2026-10-06'},a)[0],400)
        feedback={'student_id':2,'week_start':'2026-10-05','status':'フォロー必要','comment':'相談しましょう'}
        self.assertEqual(self.request('/api/weekly-feedback',feedback,a)[0],403)
        self.assertEqual(self.request('/api/weekly-feedback',feedback,self.teacher)[0],200)
        self.assertEqual(self.request('/api/weekly',{**payload,'comment':'改ざん','status':'順調'},a)[0],200)
        row=self.request('/api/weekly',auth=a)[1][0]
        self.assertEqual(row['comment'],'相談しましょう');self.assertEqual(row['status'],'フォロー必要')
        self.assertEqual(self.request('/api/weekly',{**payload,'week_start':'2025-01-06'},a)[0],200)
        self.assertEqual(len(self.request('/api/weekly',auth=a)[1]),2)
        self.assertEqual(self.request('/api/reports',auth=a)[1][0]['sales'],123456)
        self.assertEqual(self.request('/api/weekly')[0],401)

    def test_ten_failures_lock_and_manual_unlock(self):
        original=self.login('a@example.test');other=self.login('b@example.test')
        wrong={'email':'a@example.test','password':'Wrong-password-123'}
        for _ in range(9): self.assertEqual(self.request('/api/login',wrong)[0],401)
        self.login('a@example.test')  # A success resets the consecutive counter.
        for _ in range(9): self.assertEqual(self.request('/api/login',wrong)[0],401)
        self.assertEqual(self.request('/api/login',wrong)[0],423)
        self.assertEqual(self.request('/api/me',auth=original)[0],401)
        self.assertEqual(self.request('/api/login',{'email':'a@example.test','password':'Test-password-123'})[0],423)
        with sqlite3.connect(self.private/'students.sqlite3') as db:
            db.execute('UPDATE login_attempts SET started=0')
        self.assertEqual(self.request('/api/login',wrong)[0],423)
        self.assertEqual(self.request('/api/student/unlock',{'id':2},other)[0],403)
        self.assertEqual(self.request('/api/student/unlock',{'id':2},self.teacher,csrf=False)[0],403)
        self.assertEqual(self.request('/api/student/unlock',{'id':2},self.teacher)[0],200)
        self.login('a@example.test')
        self.assertEqual(self.request('/api/me',auth=original)[0],401)

    def reset_token(self):
        import re
        files=sorted((self.private/'mail-preview').glob('reset-*.json'),key=lambda p:p.stat().st_mtime_ns)
        preview=json.loads(files[-1].read_text())
        self.assertEqual(preview['to'],'a@example.test')
        return re.search(r'#reset=([a-f0-9]{64})',preview['message']).group(1)

    def test_email_password_reset(self):
        original=self.login('a@example.test')
        known=self.request('/api/password/forgot',{'email':'a@example.test'})
        unknown=self.request('/api/password/forgot',{'email':'missing@example.test'})
        self.assertEqual(known[:2],unknown[:2])
        token=self.reset_token()
        with sqlite3.connect(self.private/'students.sqlite3') as db:
            stored=db.execute('SELECT token FROM password_resets WHERE user_id=2').fetchone()[0]
            self.assertEqual(stored,hashlib.sha256(token.encode()).hexdigest())
        self.assertEqual(self.request('/api/password/reset',{'token':token,'password':'12345678'})[0],400)
        self.assertEqual(self.request('/api/password/reset',{'token':token,'password':'Reset-password-123'})[0],200)
        self.assertEqual(self.request('/api/me',auth=original)[0],401)
        self.login('a@example.test','Reset-password-123')
        self.assertEqual(self.request('/api/password/reset',{'token':token,'password':'Another-password-123'})[0],400)
        self.request('/api/password/forgot',{'email':'a@example.test'})
        expired=self.reset_token()
        with sqlite3.connect(self.private/'students.sqlite3') as db: db.execute('UPDATE password_resets SET expires=0')
        self.assertEqual(self.request('/api/password/reset',{'token':expired,'password':'Another-password-123'})[0],400)

    def test_reset_keeps_lock_and_throttles_email(self):
        with sqlite3.connect(self.private/'students.sqlite3') as db: db.execute('UPDATE users SET locked=1,failed_attempts=10 WHERE id=2')
        for _ in range(4): self.assertEqual(self.request('/api/password/forgot',{'email':'a@example.test'})[0],200)
        self.assertEqual(len(list((self.private/'mail-preview').glob('reset-*.json'))),3)
        token=self.reset_token()
        result=self.request('/api/password/reset',{'token':token,'password':'Reset-password-123'})
        self.assertEqual(result[0],200);self.assertTrue(result[1]['locked'])
        self.assertEqual(self.request('/api/login',{'email':'a@example.test','password':'Reset-password-123'})[0],423)
        self.request('/api/student/unlock',{'id':2},self.teacher)
        self.login('a@example.test','Reset-password-123')

    def test_comment_notifications(self):
        a=self.login('a@example.test')
        feedback={'student_id':2,'month':'2026-10','status':'順調','comment':'メールに含めない相談内容'}
        self.assertEqual(self.request('/api/feedback',feedback,a)[0],403)
        self.assertEqual(self.request('/api/notifications',auth=self.teacher)[1],[])
        code,result,_=self.request('/api/feedback',feedback,self.teacher)
        self.assertEqual(code,200);self.assertEqual(result['notification']['state'],'sent')
        event_id=result['notification']['id']
        preview=json.loads((self.private/'mail-preview'/f'{event_id}.json').read_text())
        self.assertEqual(preview['to'],'a@example.test');self.assertEqual(preview['from'],'info@hmr-and-co.com')
        self.assertIn('https://hmr-and-co.com/followup/',preview['message'])
        self.assertNotIn(feedback['comment'],preview['message']);self.assertNotIn('b@example.test',preview['message'])
        self.assertIsNone(self.request('/api/feedback',{**feedback,'status':'要確認'},self.teacher)[1]['notification'])
        self.assertIsNone(self.request('/api/feedback',{**feedback,'comment':''},self.teacher)[1]['notification'])
        self.assertEqual(len(self.request('/api/notifications',auth=self.teacher)[1]),1)
        weekly={'student_id':2,'week_start':'2026-10-05','status':'順調','comment':'週報の秘密の相談'}
        self.assertEqual(self.request('/api/weekly-feedback',weekly,self.teacher)[1]['notification']['state'],'sent')
        self.assertEqual(len(list((self.private/'mail-preview').glob('*.json'))),2)
        self.assertEqual(self.request('/api/notifications',auth=a)[0],403)
        self.assertEqual(self.request('/api/notification/retry',{'id':event_id},a)[0],403)
        self.assertEqual(self.request('/api/notification/retry',{'id':event_id},self.teacher,csrf=False)[0],403)
        self.assertEqual(self.request('/api/notification/retry',{'id':event_id},self.teacher)[1]['notification']['state'],'sent')
        with sqlite3.connect(self.private/'students.sqlite3') as db:
            self.assertEqual(db.execute('SELECT attempts FROM notifications WHERE id=?',(event_id,)).fetchone()[0],1)

    def test_notification_failure_and_retry(self):
        marker=self.private/'simulate-mail-failure';marker.touch()
        feedback={'student_id':2,'month':'2026-10','status':'順調','comment':'保存するコメント'}
        code,result,_=self.request('/api/feedback',feedback,self.teacher)
        self.assertEqual(code,200);self.assertEqual(result['notification']['state'],'failed')
        self.assertEqual(self.request('/api/reports',auth=self.teacher)[1][0]['comment'],feedback['comment'])
        event_id=result['notification']['id'];marker.unlink()
        self.assertEqual(self.request('/api/notification/retry',{'id':event_id},self.teacher)[1]['notification']['state'],'sent')
        with sqlite3.connect(self.private/'students.sqlite3') as db:
            self.assertEqual(db.execute('SELECT attempts FROM notifications WHERE id=?',(event_id,)).fetchone()[0],2)
        self.assertEqual(len(list((self.private/'mail-preview').glob('*.json'))),1)

    def test_backup_and_persistence(self):
        a=self.login('a@example.test')
        self.assertEqual(self.request('/api/report',{'month':'2026-10','sales':120000,'submitted':True},a)[0],200)
        code,backup,_=self.request('/api/backup',{},self.teacher)
        self.assertEqual(code,200);self.assertTrue(backup.startswith(b'SQLite format 3'))
        destination=self.private/'verify.sqlite3';destination.write_bytes(backup)
        with sqlite3.connect(destination) as db:
            self.assertEqual(db.execute('SELECT sales FROM reports WHERE student_id=2').fetchone()[0],120000)
        # Verify storage directly, not merely the in-memory HTTP response.
        with sqlite3.connect(self.private/'students.sqlite3') as db:
            self.assertEqual(db.execute('SELECT submitted FROM reports WHERE student_id=2').fetchone()[0],1)
        self.assertEqual(list(self.private.glob('backup-*')),[])

class PublicSetupTests(unittest.TestCase):
    def test_activation_key_required(self):
        with php_server(router=ROOT/'tests/https_router.php') as (base,private):
            private.mkdir();key='one-use-test-activation-key';(private/'setup-hash.txt').write_text(hashlib.sha256(key.encode()).hexdigest())
            def send(data):
                c=http.client.HTTPConnection(urlparse(base).netloc)
                c.request('POST','/api/setup',json.dumps(data),{'Content-Type':'application/json','Host':'school.example.test'})
                r=c.getresponse();status=r.status;r.read();c.close();return status
            data={'name':'講師','email':'teacher@example.test','password':'Abcd1234'}
            self.assertEqual(send(data),403)
            self.assertEqual(send({**data,'setup_key':'wrong'}),403)
            self.assertEqual(send({**data,'setup_key':key}),200)
            self.assertFalse((private/'setup-hash.txt').exists())
            self.assertEqual(send({**data,'setup_key':key}),403)

if __name__=='__main__':unittest.main()
