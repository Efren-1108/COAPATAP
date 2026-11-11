/* Exportación XLSX local de listados COAPATAP, sin dependencias externas. */
window.exportarListaCoapatap = function(titulo, encabezados, filas, archivo) {
  const xml = s => String(s ?? '').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F\uFFFE\uFFFF]/g, '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;').replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g,'');
  const col = n => { let s=''; for(n++;n;n=Math.floor((n-1)/26))s=String.fromCharCode(65+(n-1)%26)+s;return s; };
  const encabezado=['No.',...encabezados];
  const fecha=new Date().toLocaleString('es-MX');
  const data=[[`COAPATAP — ${titulo}`],[`Generado: ${fecha} | Total de registros: ${filas.length}`],[],encabezado,...filas.map((r,i)=>[i+1,...r])];
  const ancho=encabezado.length;
  const filaXml=data.map((r,ri)=>`<row r="${ri+1}">${r.map((v,ci)=>{
    const ref=col(ci)+(ri+1), style=ri===0?1:ri===3?2:0;
    return typeof v==='number' && Number.isFinite(v)
      ? `<c r="${ref}" s="${style}"><v>${v}</v></c>`
      : `<c r="${ref}" s="${style}" t="inlineStr"><is><t xml:space="preserve">${xml(v)}</t></is></c>`;
  }).join('')}</row>`).join('');
  const hoja=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="16"/><cols>${encabezado.map((h,i)=>`<col min="${i+1}" max="${i+1}" width="${i===0?7:i===1?42:23}" customWidth="1"/>`).join('')}</cols><sheetData>${filaXml}</sheetData><autoFilter ref="A4:${col(ancho-1)}${Math.max(4,data.length)}"/><mergeCells count="2"><mergeCell ref="A1:${col(ancho-1)}1"/><mergeCell ref="A2:${col(ancho-1)}2"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9"/></worksheet>`;
  const archivos={
    '[Content_Types].xml':'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels':'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml':'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Listado" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels':'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml':'<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="15"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8A190F"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    'xl/worksheets/sheet1.xml':hoja
  };
  const encoder=new TextEncoder(), partes=[], central=[];
  const u16=n=>[n&255,(n>>>8)&255], u32=n=>[n&255,(n>>>8)&255,(n>>>16)&255,(n>>>24)&255];
  const tabla=Array.from({length:256},(_,n)=>{for(let i=0;i<8;i++)n=(n&1)?(0xEDB88320^(n>>>1)):(n>>>1);return n>>>0;});
  const crc=bytes=>{let c=0xFFFFFFFF;for(const b of bytes)c=tabla[(c^b)&255]^(c>>>8);return (c^0xFFFFFFFF)>>>0;};
  let offset=0;
  for(const [nombre,contenido] of Object.entries(archivos)){
    const n=encoder.encode(nombre), b=encoder.encode(contenido), checksum=crc(b);
    const local=Uint8Array.from([...u32(0x04034b50),...u16(20),...u16(0x0800),...u16(0),...u16(0),...u16(0),...u32(checksum),...u32(b.length),...u32(b.length),...u16(n.length),...u16(0)]);
    partes.push(local,n,b);
    central.push(Uint8Array.from([...u32(0x02014b50),...u16(20),...u16(20),...u16(0x0800),...u16(0),...u16(0),...u16(0),...u32(checksum),...u32(b.length),...u32(b.length),...u16(n.length),...u16(0),...u16(0),...u16(0),...u16(0),...u32(0),...u32(offset)]),n);
    offset+=local.length+n.length+b.length;
  }
  const tamCentral=central.reduce((n,b)=>n+b.length,0),cantidad=Object.keys(archivos).length;
  const final=Uint8Array.from([...u32(0x06054b50),...u16(0),...u16(0),...u16(cantidad),...u16(cantidad),...u32(tamCentral),...u32(offset),...u16(0)]);
  const blob=new Blob([...partes,...central,final],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  const url=URL.createObjectURL(blob), enlace=document.createElement('a');enlace.href=url;enlace.download=archivo;document.body.appendChild(enlace);enlace.click();enlace.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);
};

// Exportar la totalidad de los campos que entrega la API, no sólo los visibles.
window.exportarRegistrosCoapatap = function(titulo, registros, archivo) {
    const lista = Array.isArray(registros) ? registros : [];
    const excluir = new Set(['puede_modificar', 'puede_eliminar', 'puede_enviar_stock']);
    const claves = [...new Set(lista.flatMap(obj => Object.keys(obj || {})))].filter(k => !excluir.has(k));
    // Los nombres se vuelven legibles sin alterar la estructura de los datos.
    const nombres = {
        id:'ID', num_inventario:'Número de inventario', descripcion:'Descripción',
        marca:'Marca', modelo:'Modelo', serie:'Número de serie', costo:'Costo',
        estado_fisico:'Estado físico', situacion_inventario:'Situación en inventario',
        suspendido:'Suspendido', motivo_suspension:'Motivo de suspensión',
        fecha_ingreso_stock:'Fecha de ingreso a stock', ultimo_responsable_nombre:'Último responsable',
        ultimo_area_nombre:'Última área', motivo_inservible:'Motivo de inservibilidad',
        fecha_inservible:'Fecha de declaración inservible', numero_diagnostico:'Número de diagnóstico',
        area_nombre:'Área', adscripcion_nombre:'Adscripción', empleado_nombre:'Empleado',
        numero_nomina:'Número de nómina', creado_en:'Fecha de registro'
    };
    const encabezados = claves.map(k => nombres[k] || k.replace(/_/g,' ').replace(/^./, c => c.toUpperCase()));
    const filas = lista.map(obj => claves.map(k => {
        const valor = obj[k];
        if (valor === null || valor === undefined) return '';
        if (typeof valor === 'boolean') return valor ? 'Sí' : 'No';
        if (typeof valor === 'object') return JSON.stringify(valor);
        return String(valor);
    }));
    window.exportarListaCoapatap(titulo, encabezados, filas, archivo);
};
