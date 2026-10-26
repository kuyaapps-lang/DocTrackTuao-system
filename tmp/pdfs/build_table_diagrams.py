import re, math, json
from pathlib import Path
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, black, white
import subprocess
from PIL import Image, ImageOps, ImageDraw

root=Path.cwd()
out=root/'output/pdf/table-relations'
out.mkdir(parents=True,exist_ok=True)
tables={}; edges=[]
for p in sorted((root/'database/migrations').glob('*.php')):
    text=p.read_text().split('public function down')[0]
    matches=list(re.finditer(r"Schema::(?:create|table)\(\s*'([^']+)'",text))
    for i,m in enumerate(matches):
        table=m[1]; body=text[m.end():matches[i+1].start() if i+1<len(matches) else len(text)]
        attrs=tables.setdefault(table,{})
        for s in re.findall(r'\$table->.*?;',body,re.S):
            a=re.match(r"\$table->(\w+)\(\s*(?:'([^']+)')?",s)
            if not a: continue
            typ,name=a.groups()
            if typ=='timestamps':
                attrs['created_at']=''; attrs['updated_at']=''; continue
            if typ=='rememberToken': attrs['remember_token']=''; continue
            if typ in ['morphs','nullableMorphs']:
                attrs[name+'_type']=''; attrs[name+'_id']=''; continue
            if typ=='id': name='id'
            if not name or typ in ['index','unique','foreign','dropColumn','dropForeign','dropIndex','dropConstrainedForeignId']: continue
            flag='PK' if typ=='id' else ''
            if '->primary()' in s: flag='PK'
            fk=re.search(r"->constrained\(\s*(?:'([^']+)')?",s)
            if fk:
                target=fk[1] or name.removesuffix('_id')+'s'
                flag='FK'; edges.append((table,name,target,'nullable' in s,'unique()' in s))
            if 'unique()' in s: flag+=(' / ' if flag else '')+'UQ'
            attrs[name]=flag

# Requested documentation-only omission.
tables.pop('confidentiality_levels',None)
tables['documents'].pop('confidentiality_level_id',None)
edges=list(dict.fromkeys(e for e in edges if e[2]!='confidentiality_levels'))
business=['departments','offices','roles','users','document_types','document_statuses','priorities','processing_actions','route_actions','documents','document_routes','document_processing_logs','document_attachments','document_comments','document_qr_codes','qr_code_requests','document_office_tags','notifications','audit_logs','password_reset_requests']
order=business+[t for t in tables if t not in business]
W,H=1191,842
pdf=out/'DocTrackTuao-Table-Relations.pdf'
c=canvas.Canvas(str(pdf),pagesize=(W,H))
c.setTitle('DocTrackTuao - Table Attributes and Relationships')
def label(x,y,text,size=10,bold=False):
    c.setFont('Helvetica-Bold' if bold else 'Helvetica',size);c.drawCentredString(x,y,text)
def box(x,y,w,h,text):
    c.setFillColor(white);c.rect(x-w/2,y-h/2,w,h,fill=1);c.setFillColor(black);label(x,y-3,text,10,True)
def endpoint(x,y,d,optional,many):
    # d points inward from the table edge toward the relationship line.
    if many:
        for dy in [-6,0,6]: c.line(x,y+dy,x+d*10,y)
    else:
        c.line(x+d*4,y-6,x+d*4,y+6)
    if optional:
        c.setFillColor(white);c.circle(x+d*17,y,3.5,fill=1);c.setFillColor(black)
    else:
        c.line(x+d*14,y-6,x+d*14,y+6)
def relation(x,y,parent,child,nullable,unique):
    c.line(x-50,y,x+50,y)
    endpoint(x-50,y,1,nullable,False)
    endpoint(x+50,y,-1,True,not unique)
    for dx,name in [(-96,parent),(96,child)]:
        c.setFillColor(white);c.rect(x+dx-43,y-11,86,22,fill=1);c.setFillColor(black)
        label(x+dx,y-2,name,min(8,80/max(len(name)*.52,1)),True)
for idx,t in enumerate(order):
    c.setStrokeColor(black);c.setLineWidth(1)
    c.setFillColor(black);label(W/2,804,'DocTrackTuao | '+t.upper(),20,True)
    label(W/2,780,'Table attributes and direct relationships',11)
    cx,cy=W/2,535
    attrs=list(tables[t].items()); n=len(attrs)
    # Two side columns keep labels horizontal, including large tables.
    left=attrs[:math.ceil(n/2)];right=attrs[math.ceil(n/2):]
    for side,items in [(-1,left),(1,right)]:
        count=len(items); step=min(42,470/max(count-1,1))
        for j,(name,flag) in enumerate(items):
            x=cx+side*355;y=cy+(count-1)*step/2-j*step
            c.line(cx+side*130,cy,x-side*137,y)
            c.setFillColor(white);c.ellipse(x-137,y-17,x+137,y+17,fill=1);c.setFillColor(black)
            txt=name+(' ['+flag+']' if flag else '')
            size=min(10,245/max(len(txt)*0.52,1))
            label(x,y-3,txt,size)
    box(cx,cy,260,64,t)
    rel=[e for e in edges if e[0]==t]
    incoming=not rel
    if incoming: rel=[e for e in edges if e[2]==t]
    # Compact relationship cards below the entity diagram.
    label(cx,251,'RELATED TABLES ('+('incoming' if incoming else 'outgoing')+' foreign keys)',11,True)
    if rel:
        cols=4; rows=math.ceil(len(rel)/cols); step=min(64,192/max(rows,1))
        for j,(source,field,target,nullable,unique) in enumerate(rel):
            x=155+(j%cols)*293;y=218-(j//cols)*step
            label(x,y+23,source+'.'+field+' -> '+target+'.id',6.5)
            relation(x,y,target,source,nullable,unique)
            label(x,y-23,('0..1' if nullable else '1')+' to '+('0..1' if unique else '0..many')+' (parent to child)',8)
    else:
        label(cx,208,'No declared outgoing foreign key in the migrations.',10)
    c.setStrokeColor(HexColor('#bbbbbb'));c.line(40,48,W-40,48);c.setFillColor(black)
    for lx,optional,many,txt in [(160,False,False,'Exactly one (1)'),(420,True,False,'Zero or one (0..1)'),(710,True,True,'Zero or many (0..many)')]:
        c.setStrokeColor(black);c.line(lx-28,31,lx+15,31);endpoint(lx-28,31,1,optional,many)
        label(lx+95,28,txt,9)
    label(cx,11,'PK = primary key | FK = foreign key | UQ = unique | Code-based model; live MySQL not verified | '+str(idx+1)+' / '+str(len(order)),8)
    c.showPage()
c.save()
subprocess.run(['pdftoppm','-scale-to','1490','-png',str(pdf),str(out/'render')],check=True)
thumbs=[]
for i,t in enumerate(order):

    path=out/(f'{i+1:02d}-{order[i]}.png'); next(out.glob(f'render-*{i+1:02d}.png')).replace(path)
    im=Image.open(path).convert('RGB');im.thumbnail((397,281));thumbs.append(im)
sheet=Image.new('RGB',(397*4,310*math.ceil(len(thumbs)/4)),'#dddddd')
d=ImageDraw.Draw(sheet)
for i,im in enumerate(thumbs):
    x=(i%4)*397;y=(i//4)*310;sheet.paste(im,(x,y));d.text((x+8,y+283),order[i],fill='black')
sheet.save(out/'overview.jpg')
(out/'schema-reference.json').write_text(json.dumps({'tables':tables,'foreign_keys':edges},indent=2))
print(f'Created {len(order)} pages; {len(edges)} declared FK relationships. PDF: {pdf}')


