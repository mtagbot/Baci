// Lossless PHP tokenization: edit only literal HTML text, never PHP/JS strings or stored data.
import {PHP} from '../tests/node_modules/@php-wasm/universal/index.js';
import {loadNodeRuntime} from '../tests/node_modules/@php-wasm/node/index.js';
import {readFileSync,writeFileSync,readdirSync,mkdirSync,existsSync} from 'node:fs';
import {join,dirname,relative} from 'node:path';
const root=process.cwd(),base=join(root,'.cache/ui-audit/site'),overlay=join(root,'update-v4.152.0');
const spec=JSON.parse(readFileSync(join(overlay,'assets/js/school-icons.js'),'utf8').split('window.SchoolIcons=')[1].trim().slice(0,-1));
const keys=Object.keys(spec.map).sort((a,b)=>b.length-a.length),rx=new RegExp(keys.map(x=>x.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'[\\uFE0E\\uFE0F]?').join('|'),'g');
const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:1}}));const files=[];
function walk(dir){for(const e of readdirSync(dir,{withFileTypes:true})){const p=join(dir,e.name);if(e.isDirectory()){if(!['vendor','config','sql','uploads'].includes(e.name))walk(p)}else if(e.name.endsWith('.php'))files.push(relative(base,p));}}
walk(base);let changed=[];
for(const file of files){
 if(/(?:legacy|docx_|card_|report_image|bot_helpers|tcpdf)/.test(file))continue;
 const source=existsSync(join(overlay,file))?join(overlay,file):join(base,file),s=readFileSync(source,'utf8');
 if(!rx.test(s)){rx.lastIndex=0;continue;}rx.lastIndex=0;
 const result=await php.run({code:`<?php $s=base64_decode('${Buffer.from(s).toString('base64')}');$a=[];foreach(token_get_all($s) as $t)$a[]=is_array($t)?[$t[0]===T_INLINE_HTML,$t[1]]:[false,$t];echo json_encode($a);`});
 const tokens=JSON.parse(result.text);let tag='',inTag=false,quote='',stack=[],count=0;
 function html(text){let out='',i=0;
  while(i<text.length){
   const raw=stack[stack.length-1];if(!inTag&&['script','style'].includes(raw)){const at=text.toLowerCase().indexOf('</'+raw,i);if(at<0)return out+text.slice(i);out+=text.slice(i,at);i=at;}
   const c=text[i];
   if(inTag){out+=c;tag+=c;i++;if(quote){if(c===quote)quote='';continue;}if(c==='"'||c==="'"){quote=c;continue;}if(c==='>'){inTag=false;const m=tag.match(/^<\s*(\/?)\s*([a-zA-Z0-9]+)/);if(m){const name=m[2].toLowerCase();if(m[1]){const pos=stack.lastIndexOf(name);if(pos>=0)stack.length=pos;}else if(!/\/$/.test(tag.slice(0,-1))&&!['input','img','meta','link','br','hr','area','source','wbr'].includes(name))stack.push(name);}tag='';}continue;}
   if(c==='<'){inTag=true;tag='<';out+=c;i++;continue;}
   let end=text.indexOf('<',i);if(end<0)end=text.length;let part=text.slice(i,end);
   if(!stack.some(x=>['textarea','option','title','pre','code','svg','script','style'].includes(x)))part=part.replace(rx,x=>{count++;return spec.icons[spec.map[x.replace(/[\uFE0E\uFE0F]/g,'')]].svg;});
   out+=part;i=end;
  }return out;
 }
 const next=tokens.map(([inline,t])=>inline?html(t):t).join('');
 if(next!==s){const dest=join(overlay,file);mkdirSync(dirname(dest),{recursive:true});writeFileSync(dest,next);changed.push({file,count});}
}
mkdirSync(join(root,'.cache/ui-audit'),{recursive:true});writeFileSync(join(root,'.cache/ui-audit/static-icons.json'),JSON.stringify(changed,null,2));console.log('Static HTML migration:',changed.length,'files,',changed.reduce((s,x)=>s+x.count,0),'icons');
process.exit(0);
