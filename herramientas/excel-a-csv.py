#!/usr/bin/env python3
# -----------------------------------------------------------------------------
# Convierte el Excel de claves del vendedor (columnas Size, DevEUI, AppKey,
# AppEUI) en despliegue/config/contadores.csv, que es lo que carga ChirpStack.
#
#   python3 herramientas/excel-a-csv.py "conversacionVendedor/DevEUI,AppKey,AppEUI.xlsx"
#   python3 herramientas/excel-a-csv.py <excel> [salida.csv]
#
# La columna Size (DN15, DN20...) solo viene en la primera fila de cada grupo;
# se arrastra a las siguientes. El nombre de cada contador es el número impreso
# en su carcasa (el DevEUI sin el "00" del principio). Solo usa la librería
# estándar de Python (un .xlsx es un zip con XML).
# -----------------------------------------------------------------------------
import re
import sys
import zipfile
import xml.etree.ElementTree as ET

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
T = '{%s}t' % NS['m']

CABECERA = """# Contadores a dar de alta en ChirpStack (el contenedor los carga al arrancar;
# sin reiniciar: docker exec chirpstack-contadores provisionar).
# Generado con herramientas/excel-a-csv.py a partir del Excel del vendedor.
# Una línea por contador; las que empiezan por # se ignoran.
#
# devEui y joinEui: 16 caracteres hex. appKey: 32 caracteres hex.
# nwkKey: solo para LoRaWAN 1.1 (vacío en 1.0.x).
#
# devEui,joinEui,appKey,nwkKey,nombre,descripcion
"""


def filas_excel(ruta):
    z = zipfile.ZipFile(ruta)
    textos = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', NS):
            textos.append(''.join(t.text or '' for t in si.iter(T)))
    hoja = 'xl/worksheets/sheet1.xml'
    for fila in ET.fromstring(z.read(hoja)).iter('{%s}row' % NS['m']):
        valores = {}
        for c in fila.findall('m:c', NS):
            columna = re.match(r'[A-Z]+', c.get('r')).group(0)
            v = c.find('m:v', NS)
            if c.get('t') == 's' and v is not None:
                valores[columna] = textos[int(v.text)]
            elif c.get('t') == 'inlineStr':
                valores[columna] = ''.join(t.text or '' for t in c.iter(T))
            else:
                valores[columna] = v.text if v is not None else ''
        yield valores


def main():
    if len(sys.argv) < 2:
        sys.exit('Uso: excel-a-csv.py <excel> [salida.csv]')
    salida = sys.argv[2] if len(sys.argv) > 2 else 'despliegue/config/contadores.csv'

    filas = list(filas_excel(sys.argv[1]))
    cabecera = {v.strip().lower(): k for k, v in filas[0].items()}
    for campo in ('size', 'deveui', 'appkey', 'appeui'):
        if campo not in cabecera:
            sys.exit(f'Falta la columna {campo} en la primera fila del Excel')

    lineas, vistos, errores = [], set(), []
    modelo = ''
    for n, fila in enumerate(filas[1:], start=2):
        modelo = (fila.get(cabecera['size']) or '').strip() or modelo
        dev_eui = (fila.get(cabecera['deveui']) or '').strip().lower()
        app_key = (fila.get(cabecera['appkey']) or '').strip().lower()
        app_eui = (fila.get(cabecera['appeui']) or '').strip().lower()
        if not dev_eui:
            continue
        if not re.fullmatch(r'[0-9a-f]{16}', dev_eui) or not re.fullmatch(r'[0-9a-f]{16}', app_eui) or not re.fullmatch(r'[0-9a-f]{32}', app_key):
            errores.append(f'fila {n}: claves con formato incorrecto ({dev_eui})')
            continue
        if dev_eui in vistos:
            errores.append(f'fila {n}: DevEUI repetido {dev_eui}')
            continue
        vistos.add(dev_eui)
        numero = dev_eui[2:] if dev_eui.startswith('00') else dev_eui
        lineas.append(f'{dev_eui},{app_eui},{app_key},,{modelo} {numero},Contador {modelo}')

    if errores:
        sys.exit('Errores en el Excel:\n  ' + '\n  '.join(errores))
    with open(salida, 'w') as f:
        f.write(CABECERA + '\n'.join(lineas) + '\n')

    por_modelo = {}
    for linea in lineas:
        m = linea.split(',')[4].split(' ')[0]
        por_modelo[m] = por_modelo.get(m, 0) + 1
    resumen = ', '.join(f'{m}: {c}' for m, c in por_modelo.items())
    print(f'{len(lineas)} contadores ({resumen}) -> {salida}')


if __name__ == '__main__':
    main()
