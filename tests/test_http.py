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
        self.assertEqual(self.request('/api/setup',{'name':'講師','email':'teacher@example.test','password':'test-password-123'})[0],200)
        self.teacher=self.login('teacher@example.test')
        for name,email in [('生徒A','a@example.test'),('生徒B','b@example.test')]:
            self.assertEqual(self.request('/api/students',{'name':name,'email':email,'password':'test-password-123'},self.teacher)[0],200)

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

    def login(self,email,password='test-password-123'):
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
        self.assertEqual(self.request('/api/students',{'name':'evil','email':'evil@example.test','password':'test-password-123'},a)[0],403)
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
        self.assertEqual(self.request('/api/setup',{'name':'evil','email':'evil@example.test','password':'test-password-123'})[0],403)

    def test_profile_password_and_graduation(self):
        a=self.login('a@example.test')
        profile={'id':2,'name':'生徒A改','email':'new-a@example.test','prefecture':'東京都','cohort':'1期','start_date':'2026-10-01'}
        self.assertEqual(self.request('/api/student/profile',profile,a)[0],403)
        self.assertEqual(self.request('/api/student/profile',profile,self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[1]['user']['name'],'生徒A改')
        self.assertEqual(self.request('/api/student/password',{'id':2,'password':'replacement-password-123'},a)[0],403)
        self.assertEqual(self.request('/api/student/password',{'id':2,'password':'replacement-password-123'},self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)
        a=self.login('new-a@example.test','replacement-password-123')
        self.assertEqual(self.request('/api/password',{'current':'replacement-password-123','password':'new-password-123'},a)[0],200)
        self.assertEqual(self.request('/api/student/update',{'id':2,'active':False},self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)
        self.assertEqual(self.request('/api/student/update',{'id':2,'active':True},self.teacher)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)
        a=self.login('new-a@example.test','new-password-123')
        self.assertEqual(self.request('/api/logout',{},a)[0],200)
        self.assertEqual(self.request('/api/me',auth=a)[0],401)

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
            data={'name':'講師','email':'teacher@example.test','password':'test-password-123'}
            self.assertEqual(send(data),403)
            self.assertEqual(send({**data,'setup_key':'wrong'}),403)
            self.assertEqual(send({**data,'setup_key':key}),200)
            self.assertFalse((private/'setup-hash.txt').exists())
            self.assertEqual(send({**data,'setup_key':key}),403)

if __name__=='__main__':unittest.main()
