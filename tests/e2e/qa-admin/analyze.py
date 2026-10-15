import json,sys
R=json.load(open(sys.argv[1] if len(sys.argv)>1 else 'results.json'))
noise=lambda s:'Cross-Origin-Opener-Policy' in s or 'ProjectDeniedMapError' in s
fnoise=lambda s:'mapfiles/transparent.png' in s
for r in R:
    if r['url']=='SIDEBAR': print('SIDEBAR',r['who'],r['console']); continue
    exp=r.get('expected',200)
    c=[x for x in r['console'] if not noise(x) and not (exp==403 and '403' in x)]
    r['failed']=[x for x in r['failed'] if not fnoise(x)]
    issues=[]
    if r['status']!=exp: issues.append(f"status {r['status']}!={exp}")
    if c: issues.append('console:'+' | '.join(c[:3]))
    if r['pageErrors']: issues.append('pageerr:'+' | '.join(r['pageErrors'][:2]))
    if r['failed']: issues.append('failed:'+' | '.join(r['failed'][:3]))
    if r['overflow']>0: issues.append(f"overflow {r['overflow']}")
    if r['brokenImgs']: issues.append('imgs')
    if not r['h1'] and r['status']==200: issues.append('no h1')
    if issues: print(r['who'],r['url'],r['vp'],r['theme'],'::',' ; '.join(issues))
