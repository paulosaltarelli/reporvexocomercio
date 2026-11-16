/* Keep the visible permission matrix consistent with the dependency closure validated by PHP. */
(() => {
    const source=document.querySelector('#permission-dependencies');
    if(!source)return;
    const dependencies=JSON.parse(source.textContent);
    const fields=new Map([...document.querySelectorAll('input[name="permissions[]"]')].map(field=>[field.value,field]));
    function include(key,visited=new Set()){
        if(visited.has(key))return;visited.add(key);
        for(const needed of dependencies[key]||[]){const field=fields.get(needed);if(field){field.checked=true;include(needed,visited);}}
    }
    for(const field of fields.values()){
        if(field.checked)include(field.value);
        field.addEventListener('change',()=>{
            if(field.checked){include(field.value);return;}
            let changed;
            do{changed=false;for(const candidate of fields.values())if(candidate.checked&&(dependencies[candidate.value]||[]).some(key=>!fields.get(key)?.checked)){candidate.checked=false;changed=true;}}while(changed);
        });
    }
})();
