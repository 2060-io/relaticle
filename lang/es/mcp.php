<?php

declare(strict_types=1);

return [
    'connection' => [
        'fallback_name' => 'Un asistente de IA',
    ],

    'consent' => [
        'title' => 'Autorizar a :client',
        'intro' => [
            'one' => ':client solicita conectarse a :workspace.',
            'choose' => ':client solicita conectarse a uno de sus espacios de trabajo.',
            'requested' => ':client solicita conectarse a un espacio de trabajo del que usted no es miembro.',
        ],
        'redirect' => 'Después de autorizar, volverá a :host. Continúe solo si confía en ese sitio.',
        'signed_in_as' => 'Sesión iniciada como :email.',
        'invalid_resource' => 'Esta conexión solicitó acceso a un servidor distinto. Relaticle solo autoriza conexiones a su propio MCP server.',

        'workspace' => [
            'heading' => '¿Qué espacio de trabajo?',
            'description' => 'Para usar otro más adelante, revoque este conector y agréguelo de nuevo.',
            'aria_label' => 'Selección de espacio de trabajo',
            'personal' => 'Personal',
            'paused' => 'En pausa. Suscríbase para conectar',
            'all_paused' => 'Todos los espacios de trabajo de esta cuenta están en pausa. Suscríbase a un plan de Cloud antes de conectar. Un conector autorizado en un espacio de trabajo en pausa no puede leer ni escribir datos.',
            'none' => [
                'heading' => 'No pertenece a ningún espacio de trabajo.',
                'description' => 'Cree un espacio de trabajo o únase a uno en Relaticle antes de autorizar este conector.',
            ],
            'requested' => [
                'not_member' => [
                    'heading' => 'Usted no es miembro del espacio de trabajo que solicita :client.',
                    'description' => ':client solo puede conectarse a ese espacio de trabajo, así que solo puede cancelar. Pida a un propietario del espacio de trabajo que lo invite, o inicie sesión con la cuenta que es miembro.',
                ],
                'paused' => [
                    'heading' => ':workspace está en pausa.',
                    'description' => ':client solo puede conectarse a este espacio de trabajo, así que solo puede cancelar. Suscríbase primero a un plan de Cloud: un conector autorizado en un espacio de trabajo en pausa no puede leer ni escribir datos.',
                ],
            ],
        ],

        'permissions' => [
            'heading' => ':client podrá',
            'read' => 'Leer y buscar sus registros',
            'write' => 'Crearlos y actualizarlos',
            'delete' => 'Eliminarlos. Puede restaurar los registros eliminados.',
            'email_read' => 'Leer el correo que usted puede ver en este espacio de trabajo',
            'email_draft' => 'Guardar borradores de correo para que usted los revise',
            'email_send' => 'Enviar correo en su nombre, tras una espera que puede cancelar',
            'none' => 'Su rol en este espacio de trabajo no permite nada de lo que :client solicitó.',
            'excluded' => 'No puede acceder a sus otros espacios de trabajo, a los miembros del espacio de trabajo, a la facturación ni a la configuración de la cuenta.',
        ],

        'actions' => [
            'cancel' => 'Cancelar',
            'authorize' => 'Autorizar',
            'authorizing' => 'Autorizando...',
        ],

        'revoke_hint' => 'Revoque este conector en cualquier momento desde la página de tokens de acceso.',
    ],
];
