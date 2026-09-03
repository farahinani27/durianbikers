import sys, json, struct
p = sys.argv[1]
d = open(p,'rb').read()
assert d[:4]==b'glTF', 'not a GLB'
off, n = 12, len(d)
js = None
while off < n:
    ln, ty = struct.unpack_from('<II', d, off)
    if ty == 0x4E4F534A: js = json.loads(d[off+8:off+8+ln])
    off += 8+ln
print('file      :', p)
print('meshes    :', len(js.get('meshes',[])))
print('materials :', len(js.get('materials',[])))
print('textures  :', len(js.get('textures',[])))
print('images    :', len(js.get('images',[])))
for i,im in enumerate(js.get('images',[])):
    print('   image',i, im.get('mimeType'), 'uri=' + str(im.get('uri')) if im.get('uri') else '(embedded)')
uv = set(); col = set()
for m in js.get('meshes',[]):
    for pr in m.get('primitives',[]):
        for a in pr.get('attributes',{}):
            if a.startswith('TEXCOORD'): uv.add(a)
            if a.startswith('COLOR'): col.add(a)
print('UV sets   :', sorted(uv) or 'NONE  <-- no UVs, texture cannot be applied')
print('vtx color :', sorted(col) or 'none')
for i,m in enumerate(js.get('materials',[])):
    pbr = m.get('pbrMetallicRoughness',{})
    print('   mat',i, m.get('name'), '| baseColorTexture:', 'YES' if 'baseColorTexture' in pbr else 'no',
          '| baseColorFactor:', pbr.get('baseColorFactor'))
