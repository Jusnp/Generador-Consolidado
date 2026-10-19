<?php

namespace App\Programas\LaMaria;

/**
 * FALLBACK TEMPORAL de La María: clasificación de servicios de las notas
 * técnicas oficiales (columna "Servicio" de la hoja "servicios y tecnologías"):
 *
 * - NOTA TÉCNICA PRÓSTATA.xlsx
 * - Anexo 1. NOTA TECNICA FINAL Cervix (3).xlsx
 *
 * Existe solo porque la nota técnica activa de Próstata ("Nota Tecnica
 * Prostata Chat.xlsx") tiene 8 servicios y no trae la clasificación oficial.
 * La precedencia es: metadatos.servicio de la nota de la ruta, luego
 * metadatos.agrupador de la nota de la ruta y, solo si la nota no clasifica el
 * código, esta clase. Cuando se cargue la nota oficial de Próstata (el
 * importador ya guarda metadatos.servicio) esta clase se puede retirar sin
 * cambiar el comportamiento.
 *
 * Solo clasifica: no aporta tarifas ni reemplaza el catálogo activo, que sigue
 * siendo la única fuente de tarifa.
 */
final class ClasificacionNotaTecnicaOficial
{
    /**
     * Servicio "Procedimientos quirúrgicos" (90 códigos).
     *
     * @var list<string>
     */
    public const PROCEDIMIENTOS_QUIRURGICOS = [
        '053101', '053113', '053206', '053207', '053301', '324101', '324102', '324201', '341403', '345302',
        '399200', '405304', '405306', '405404', '405405', '405406', '405407', '405408', '405411', '405503',
        '454203', '454210', '483101', '542802', '550202', '559101', '559300', '559301', '561102', '563102',
        '571210', '571220', '573201', '574201', '574202', '574203', '575202', '576001', '576011', '576060',
        '576061', '577301', '577303', '578502', '580050', '585010', '592103', '592204', '598001', '598002',
        '599401', '601103', '601104', '601201', '602001', '602002', '602003', '602005', '605101', '605112',
        '609001', '623001', '671201', '671202', '672001', '673102', '673201', '674002', '674004', '674501',
        '684003', '684020', '684103', '684104', '685102', '686001', '686011', '686102', '687001', '690103',
        '702203', '703302', '776203', '776503', '793501', '810821', '819004', '877863', '882840', '976203',
    ];

    /**
     * Servicio "Laboratorio Clínico" (151 códigos).
     *
     * @var list<string>
     */
    public const LABORATORIO_CLINICO = [
        '681105', '702201', '898002', '898014', '898015', '898016', '898018', '898020', '898101', '898201',
        '898221', '898241', '898801', '898807', '898808', '898809', '898812', '901002', '901107', '901206',
        '901235', '901236', '901237', '902042', '902045', '902047', '902048', '902049', '902104', '902106',
        '902108', '902204', '902206', '902210', '902211', '902213', '902219', '902220', '902223', '902224',
        '903016', '903017', '903026', '903044', '903046', '903105', '903106', '903111', '903603', '903604',
        '903703', '903706', '903707', '903801', '903802', '903803', '903805', '903809', '903811', '903812',
        '903813', '903815', '903816', '903817', '903818', '903821', '903822', '903823', '903824', '903828',
        '903829', '903830', '903833', '903834', '903835', '903836', '903838', '903839', '903841', '903846',
        '903852', '903854', '903855', '903856', '903857', '903859', '903860', '903861', '903862', '903863',
        '903864', '903865', '903866', '903867', '903868', '903895', '904004', '904105', '904107', '904108',
        '904503', '904508', '904601', '904602', '904706', '904801', '904807', '904902', '904904', '904912',
        '904920', '904921', '904922', '904924', '904925', '906249', '906303', '906406', '906418', '906422',
        '906423', '906440', '906602', '906603', '906605', '906610', '906611', '906625', '906812', '906851',
        '906906', '906908', '906910', '906912', '906913', '906914', '906915', '907002', '907003', '907004',
        '907106', '907107', '908832', '911003', '911008', '911009', '911010', '911015', '911017', '911019',
        '911021',
    ];

    /**
     * Servicio "Imágenes Diagnósticas" (83 códigos).
     *
     * @var list<string>
     */
    public const IMAGENES_DIAGNOSTICAS = [
        '053114', '332601', '441302', '452301', '601101', '601102', '871030', '871040', '871121', '872104',
        '872105', '877812', '877816', '877862', '879111', '879112', '879113', '879131', '879161', '879201',
        '879205', '879301', '879391', '879410', '879420', '879421', '879430', '879431', '879460', '879910',
        '879990', '881132', '881202', '881301', '881302', '881305', '881306', '881314', '881318', '881332',
        '881360', '881390', '881401', '881402', '881501', '881502', '881701', '881702', '882203', '882292',
        '882294', '882308', '882317', '882318', '883101', '883210', '883211', '883220', '883221', '883230',
        '883231', '883233', '883234', '883235', '883301', '883401', '883434', '883435', '883440', '883511',
        '883512', '883522', '883903', '895100', '895101', '898001', '920901', '920902', '920903', '920904',
        '921401', '921402', '921405',
    ];

    /**
     * Servicio "Consulta externa" (50 códigos).
     *
     * @var list<string>
     */
    public const CONSULTA_EXTERNA = [
        '890201', '890202', '890205', '890206', '890208', '890209', '890226', '890227', '890233', '890235',
        '890237', '890243', '890246', '890250', '890251', '890264', '890266', '890267', '890273', '890278',
        '890280', '890286', '890287', '890294', '890301', '890302', '890305', '890306', '890308', '890309',
        '890326', '890333', '890335', '890337', '890343', '890346', '890350', '890351', '890364', '890366',
        '890373', '890378', '890380', '890386', '890387', '890394', '890502', '890503', '998701', '998702',
    ];

    /**
     * Servicio "Urgencias" (2 códigos).
     *
     * @var list<string>
     */
    public const URGENCIAS = [
        '890701', '890702',
    ];

    /**
     * Servicio "Terapias" (1 códigos).
     *
     * @var list<string>
     */
    public const TERAPIAS = [
        '931001',
    ];

    /**
     * Servicio "Procedimientos Diagnosticos y Terapeuticos" (16 códigos).
     *
     * @var list<string>
     */
    public const PROCEDIMIENTOS_DIAGNOSTICOS_Y_TERAPEUTICOS = [
        '039204', '452305', '579401', '579501', '860101', '881202', '911107', '912002', '920802', '920812',
        '922801', '922803', '922804', '922806', '931501', '976501',
    ];

    /**
     * Códigos cuya descripción en la nota técnica es una gammagrafía.
     *
     * @var list<string>
     */
    public const GAMMAGRAFIAS = ['920901', '920902', '920903', '920904'];

    /**
     * Códigos 883xxx cuya descripción en la nota técnica es una resonancia magnética.
     *
     * @var list<string>
     */
    public const RESONANCIAS = [
        '883101', '883210', '883211', '883220', '883221', '883230', '883231', '883233', '883234', '883235',
        '883301', '883401', '883434', '883435', '883440', '883511', '883512', '883522', '883903',
    ];

    /**
     * Tomografías por emisión de positrones (PET-TC) de la nota técnica.
     *
     * @var list<string>
     */
    public const PET_TC = ['921401', '921402', '921405'];

    /**
     * @return list<string>
     */
    public static function categorias(string $codigo): array
    {
        $categorias = [];

        foreach ([
            'PROCEDIMIENTOS_QUIRURGICOS' => self::PROCEDIMIENTOS_QUIRURGICOS,
            'LABORATORIO_CLINICO' => self::LABORATORIO_CLINICO,
            'IMAGENES_DIAGNOSTICAS' => self::IMAGENES_DIAGNOSTICAS,
            'CONSULTA_EXTERNA' => self::CONSULTA_EXTERNA,
            'URGENCIAS' => self::URGENCIAS,
            'TERAPIAS' => self::TERAPIAS,
            'PROCEDIMIENTOS_DIAGNOSTICOS_Y_TERAPEUTICOS' => self::PROCEDIMIENTOS_DIAGNOSTICOS_Y_TERAPEUTICOS,
        ] as $categoria => $codigos) {
            if (in_array($codigo, $codigos, true)) {
                $categorias[] = $categoria;
            }
        }

        return $categorias;
    }
}
