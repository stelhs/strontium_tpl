<?php
/**
*    Шаблонизатор StrontiumTPL. 
*    By Michail Kurochkin stelhs@ya.ru
*    18.08.2008
*/

    class strontium_tpl
    {
        // Содержимое исходного шаблона
        var $source_content;

        // Содержимое заполненного шаблона (используется в режиме интерпретации)
        var $result_content;

        // Список меток и их значений которые передаются по умолчанию в любой вызов assign()
        var $default_marks_val;

        // Флаг, режим компиляции разрешен или нет (1 или 0)
        var $enable_compile;

        // Стек, в режиме компиляции для выполнения метода assign необходим стек
        var $assign_stack;

        // В режиме компиляции массив заполняется данными из компилированного шаблона.
        // Содержит в себе древовидный ассоциативный массив взаимосвязи блоков с подблоками
        var $compiled_struct_tree;

        // Дерево данных для вывода в компилированный шаблон.
        // В режиме компиляции вызов метода assign формирует и дополняет этот деревовидный массив
        var $assign_tree;
        

        /**
            Конструктор класса
            @param $filename - Имя файла шаблона
            @param $default_marks - Массив меток с их значениями
            @param $enable_compile - включить режим компиляции
        */
        function strontium_tpl($filename = '', $default_marks = array(), $enable_compile = true)
        {
            if ($filename)
                $this->open($filename, $default_marks, $enable_compile);
            
            $this->assign_stack[] = 'root';
        }

        /**
            Открывает шаблон из файла
            @param $filename - путь и имя файла шаблона
            @param $default_marks - метки по умолчанию
            @param $enable_compile - Флаг разрешения компиляции шаблонов
            @return возвращает список блоков и их содержимое
        */
        function open($filename, $default_marks = array(), $enable_compile = false)
        {
            if (!file_exists($filename)) {
                echo 'Error: Teamplate "' . $filename . '" not exist';
                exit();
            }
            
            if ($default_marks)
                $this->set_default_marks($default_marks);

            $this->enable_compile = $enable_compile;
            
            $path = $this->get_file_path($filename);
            $file = $this->get_file_name($filename);

            // для режима интерпретации
            if (!$enable_compile) {
                $tpl_content = file_get_contents($filename);
                $tpl_content = $this->do_insert_blocks($tpl_content,
                                                       $this->get_file_path($filename));

                // загрузка некомпилированного шаблона
                return $this->open_buffer($tpl_content);
            }
            
            // для режима компиляции

            // если компилируемый файл отсутсвует или присутствует
            // файл debug то осущевствляется компиляция шаблона
            if ((!file_exists($path . '.compiled/' . $file . '.php')) ||
                file_exists($path . 'debug')) {
                $tpl_content = file_get_contents($filename);
                $tpl_content = $this->do_insert_blocks($tpl_content,
                                                       $this->get_file_path($filename));
                $tpl_content = $this->strip_comments($tpl_content);
                $this->tpl_compile_teamplate($tpl_content, $filename);
            }
            
            // Опускаем флаг разрешающий запуск шаблона
            $run_teamplate = 0;
            
            // Подключение компилированного шаблона
            require($path . '.compiled/' . $file . '.php');
            
            // Сохранение имени файла шаблона, для последующей подгрузки шаблона в методе make_result()
            $this->tpl_filename = $filename;
        }


        /**
            Открывает шаблон из буфера
            @param $tpl_content - текст шаблона
            @return возвращает список блоков с их содержимым
        */
        function open_buffer($tpl_content)
        {
            if ($this->enable_compile) {
                echo 'Error: open_buffer() is not work in compiled mode';
                exit;
            }
            
            $tpl_content = $this->strip_comments($tpl_content);

            // Сохранение шаблона для дальнейшей обработки
            $this->result_content = $this->source_content = $tpl_content;
            
            // Замена всех блоков на временные позывные метки
            $blocks = $this->find_blocks('BLOCK', $this->result_content);
            foreach ($blocks as $block) {
                foreach($block as $block_name => $block_data);
                // Сохронение содержимого найденного блока
                $list_blocks[$block_name] = $block_data;
                // В результирующем контенте удаляется содержимое блоков,
                // вместо блоков временно ставятся блоковые метки в формате <<-имя_блока->>
                $this->result_content = $this->replace_block("BLOCK",
                                                             $this->result_content,
                                                             $block_name,
                                                             '<<-' . $block_name . '->>');
            }
            
            // Возврат списока блоков с их содержимым
            return $list_blocks;
        }
        
        
        /**
            Заполнение указанного блока данными
            @param $block_name - Имя блока
            @param $data - список меток и их значения
        */
        function assign($assign_block = false, $data = false)
        {
            // Добавление меток по умолчанию и их значений 
            if ($this->default_marks_val) {
                if (!is_array($data))
                    $data = array();
                    
                foreach ($this->default_marks_val as $mark => $val)
                    // Если такая метка ранее небыла определена
                    if (!isset($data[$mark]))
                        $data[$mark] = $val;
            }

            // Если включен режим компиляции,
            // то дополняем дерево данных компилированного шаблона данными $data
            if ($this->enable_compile) {
                // Получение текущиего блока в который производится асайн
                $curr_block = array_pop($this->assign_stack);
                array_push($this->assign_stack, $curr_block);

                // Если асайн в тотже блок, куда асайнили в предыдущий раз
                if($assign_block == $curr_block) {
                    // Добавление данных в дерево
                    $this->add_node_to_assign_tree($this->assign_stack, $data);
                    return;
                }
                
                // Извлечение из стека блоков (по пути назад) и на каждом уровне
                // ищется дочерний блок соответсвующий $assign_block
                // сохраняется копия стека на случай если имя блока окажется несуществующим
                $stack = $this->assign_stack;
                while ($parent_block = array_pop($stack)) {
                    // Для каждого блока parent_block в стеке, 
                    // извлекается список дочерних блоков
                    $children_blocks = 
                        $this->get_children_blocks_by_tree($parent_block,
                                                           $this->compiled_struct_tree);
                    // Если дочерних блоков не найденно, то явно что чтото нетак
                    if(!$children_blocks)
                        return;

                    $key = array_search($assign_block, $children_blocks);
                    // Если блок $assign_block найден в списке дочерних блоков
                    if ($key !== FALSE) {
                        array_push($stack, $parent_block);
                        $this->assign_stack = $stack;
                        
                        // добавляется в стек текущий блок
                        array_push($this->assign_stack, $assign_block);

                        // добавляются данные в дерево
                        $this->add_node_to_assign_tree($this->assign_stack, $data);
                        return;
                    }
                }

                return;
            }
        
            // Если режим компиляции выключен, то выполняем весь остальной код

            // Если явно задан блок, то загрузка блока
            if ($assign_block)
                $content = $this->load_block($assign_block);
            else 
                // Иначе загрузка всего контента
                $content = $this->source_content;
            
            // Если в загруженном шаблоне встретились блоки
            while (preg_match("/<!--[ ]*START[ ]+BLOCK[ ]*:[ ]*([a-zA-Z0-9_\.\/]+)[ ]*-->/s",
                             $content,
                             $matches)) {
                // то в результирующем шаблоне производится замена блоковых меток
                // на реальные данные блоков

                // В результирующем контенте удаляется содержимое блоков
                // и вмcето них временно устанавливаются блоковые метки в формате <<-имя_блока->>
                $content = $this->replace_block("BLOCK",
                                                $content,
                                                $matches[1],
                                                '<<-' . $matches[1] . '->>');
                $this->result_content = str_replace('<<-'.$matches[1].'->>',
                                                    "",
                                                    $this->result_content);
            }
            
            // Если есть что заасайнить, то производится асайн
            if (is_array($data))
                foreach($data as $key => $value)
                    $content = str_replace('{'.$key.'}', $value, $content);

            // Если асайнится в конкректный блок
            if ($assign_block) {
                // Вставка перед блоковой меткой заполненненого кода блока
                $this->result_content = str_replace('<<-'.$assign_block.'->>',
                                                    $content . '<<-' . $assign_block . '->>',
                                                    $this->result_content);
                return;
            }

            // Если блок является корневым,
            // то обновляется source_content.
            // Это нужно для того, чтобы в корневой блок
            // можно было асайнить много раз подряд
            $this->result_content = $content; 
            
            // поиск всех блоковых меток и замена сформированными данными
            preg_match_all("/<<-(.*)->>/Us", $content, $extract);
            $list_blocks = $extract[1];
            foreach ($list_blocks as $block_name) {
                $block_src = $this->find_block('BLOCK',
                                               $block_name,
                                               $this->source_content);
                $content = preg_replace("/(<<-" . $block_name . "->>)/Us",
                                        '<!-- START BLOCK : ' . $block_name . ' -->' .
                                        $block_src . '<!-- END BLOCK : ' . $block_name . ' -->',
                                        $content);
            }

            $this->source_content = $content; 
        }
        
        
        /**
            Осуществить рекурсивный ассайн дерева блоков с метками.
            @param $assign_data - Дерево блоков с метками
        */
        function assign_array($assign_data)
        {
            if (!$assign_data['block'])
                return;

            foreach($assign_data['block'] as $assign_block_data) {
                foreach($assign_block_data as $block_name => $block_data);
                $this->assign($block_name, $block_data['marks']);

                // Рекурсивный вызов для внутреннего блока
                if($block_data['block'])
                    $this->assign_array($block_data);
            }
        }
        
        
        /**
            Получить заполненный шаблон
            @return результирующий текст сформированный из шаблонов и данных
        */
        function result()
        {
            // Если включен режим компиляции, то запускается
            // формирование данных из компилированного шаблона
            if($this->enable_compile) {
                $path = $this->get_file_path($this->tpl_filename);
                $file = $this->get_file_name($this->tpl_filename);

                $block_root = $this->assign_tree['<blocks>']['root'][0];
                $run_teamplate = 1; // Устанавливка режима запуска шаблона
                // подключение шаблона
                require($path . '.compiled/' . $file . '.php');
                return $compiled_block_root;
            }
            
            // Режим компиляции

            // отчистка от ненужных блоковых меток
            $this->result_content = preg_replace("/(<<-.*->>)/Us", "", $this->result_content);

            // отчистка от простых меток
            $this->result_content = preg_replace("/({\w+})/Us", "", $this->result_content);

            // отчистка от HTML коментариев
            $this->result_content = preg_replace("/(<!--.*->)/Us", "", $this->result_content);
            return $this->result_content;
        }
        
        /**
            Установка меток поумолчанию.
            Эти метки будут добавленны во все блоки в которые будет вызываться assign
            @param $data - список меток и их значений
        */
        function set_default_marks($data)
        {
            $this->default_marks_val = $data;
        }

        /**
            Получить исходное содержимое блока
            @param $block_name - Имя блока
            @return содержимое блока
        */
        function load_block($block_name)
        {
            if (!$block_name)
                return;
            
            return $this->find_block('BLOCK', $block_name, $this->source_content);
        }

        
        /**
            Поиск статических меток и их значений для
            их дальнейшей подгрузки в подгружаемый шаблон
            Статические метки имею формат <mark></mark> где mark - имя метки
            Такие метки могут встречаться только в области между START INSERT и END INSERT
            @param $buffer - Текст шаблона
            @return массив в формате (метка => значение)
        */
        private function find_static_marks_values($buffer) 
        {
            $marks_info = array(); 
            // Удаление всех ассайнов на блоки
            $buffer = preg_replace("/<!--[ ]*START[ ]+ASSIGN[ ]*:.*-->.*<!--[ ]*END[ ]+ASSIGN[ ]*:.*-->/Us",
                                   '',
                                   $buffer);

            // поиск меток
            preg_match_all("/<([a-zA-Z0-9_-]+)>/Us", $buffer, $extract);
            $marks = $extract[1];

            // получение значений каждой метки
            foreach($marks as $mark) {
                preg_match("/<" . $mark .">(.*)<\/" . $mark .">/Us", $buffer, $extract);
                $marks_info[$mark] = trim($extract[1]);
            }
            
            // Возврат отчета в формате имя_метки => значение
            return $marks_info; 
        }
        
        
        /**
            Поиск блоков $block_type типа
            @param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
            @param $buffer - Текст шаблона
            @return массив в формате (имя_блока->содержимое_блока)
        */
        private function find_blocks($block_type, $buffer)
        {
            $blocks_data = array();
            $found_blocks = 1;
            while($found_blocks) {
                preg_match("/<!-- *START +" . $block_type . " *: *([a-zA-Z0-9_\.\/]*) *-->/s",
                           $buffer, $found_blocks); 

                $block_name = $found_blocks[1];
                if($block_name) {
                     // Извлечение данных блока
                    $rc = preg_match("/<!-- *START +" . 
                                         $block_type . 
                                         " *: *" . 
                                         $this->shielding_block_name($block_name) . 
                                         " *-->(.*)<!-- *END +" .
                                         $block_type .
                                         " *: *" .
                                         $this->shielding_block_name($block_name) .
                                         " *-->/Us", $buffer, $matches);
                    if(!$rc) {
                        echo 'Not found end block: <&iexcl;-- END ' .
                              $block_type . ' : ' . $block_name . ' -->';
                        exit;
                    }
                    
                    $block_data = $matches[1];

                    // Удаление найденного блока из буфера, чтобы не натыкаться на него повторно
                    $buffer = $this->replace_block($block_type, $buffer, $block_name, '');

                    // Добавление в отчет найденный блок и его содержимое
                    $blocks_data[][$block_name] = $block_data;
                }
            }
            
            return $blocks_data;
        }
        
        
        /**
            Экранирование служебных символов
            которые могут встретиться в имени блока.
            Символы перечисленны в массиве $chars
            @param $str - имя блока
            @return экранированное имя блока
        */
        private function shielding_block_name($str)
        {
            $chars = array('.', '/', ',');
            foreach ($chars as $char)
                $str = str_replace($char, "\\" . $char, $str);
            
            return $str;
        }
        
        
        /**
            Поиск блока $block_type типа с именем $block_name
            @param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
            @param $block_name - Имя блока
            @param $buffer - Текст шаблона
            @return содержимое найденного блока
        */
        private function find_block($block_type, $block_name, $buffer)
        {
            preg_match("/<!--[ ]*START[ ]+" .
                       $block_type .
                       "[ ]*:[ ]*" .
                       $block_name .
                       "[ ]*-->(.*)<!--[ ]*END[ ]+" .
                       $block_type .
                       "[ ]*:[ ]*" .
                       $block_name .
                       "[ ]*-->/Us", $buffer, $matches);
            return $matches[1];
        }
        
        
        /**
            Поиск и замена сожержимого указанного блока на строку $replace
            @param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
            @param $buffer - Текст шаблона
            @param $block_name - Имя блока
            @param $replace - Замена сожержимого указанного блока на строку $replace
        */
        private function replace_block($block_type, $buffer, $block_name, $replace)
        {
            $block_name = $this->shielding_block_name($block_name);
            return preg_replace("/<!--[ ]*START[ ]+" .
                                $block_type .
                                "[ ]*:[ ]*" .
                                $block_name .
                                "[ ]*-->(.*)<!--[ ]*END[ ]+" .
                                $block_type .
                                "[ ]*:[ ]*" .
                                $block_name .
                                "[ ]*-->/Us", $replace, $buffer, 1);
        }
        
        
        /**
            Рекурсивная функция возвращает древовидный массив всех ASSIGN блоков с метками
            @param $preassign_content - текст в котором необходимо провести поиск
        */
        private function get_preassign_data($preassign_content)
        {
            $preassigned_data = array();
             // Получение списка статических меток
            $preassigned_data['marks'] = $this->find_static_marks_values($preassign_content);
            // поиск ASSIGN блоков
            $assign_blocks = $this->find_blocks('ASSIGN', $preassign_content);
            if ($assign_blocks)
                // перебор всех найденных ASSIGN блоков
                foreach ($assign_blocks as $assign_block) {
                    // Получение имени первого ASSIGN блока и его содержимого
                    foreach ($assign_block as $block_name => $block_data);

                    // Рекурсивный вызов для поиска меток и блоков в найденном ASSIGTN блоке
                    $preassigned_data['block'][][$block_name] = $this->get_preassign_data($block_data);
                }
                
            // Возврат дерева ASSIGN блоков с метками
            return $preassigned_data;
        }
        
        
        /**
            Получить путь к файлу из полного имени файла
            @param $filename - Полный путь к файлу с именем файла
            @return Путь к файлу
        */
        private function get_file_path($filename)
        {
            // Если в имени файла встречается хотябы один символ '/'
           	if (strchr($filename, '/'))
                return substr($filename, 0, strrpos($filename, '/') + 1);
                
            return '';
        }


        /**
            Получить имя файла из полного пути к файлу
            @param $full_name - Полный путь к файлу с именем файла
            @return имя файла
        */
        private function get_file_name($full_name)
        {
            // Если в имени файла встречается хотябы один символ '/'
            if(strchr($full_name, '/'))
                return substr($full_name, strrpos($full_name, '/') + 1, strlen($full_name));
                
            return $full_name;
        }
            
        
        /**
            Рекурсивная функция разворачивает все INSERT блоки и
            возвращает развернутый шаблон
            @param $tpl_content - Текст шаблона
            @param $tpl_path - Путь к шаблону
            @param $parent_file - внутренний параметер используется для рекурсивного обхода INSERT блоков.
                                  Путь к родительскому файлу внутри которого разворачивается текущий INSERT
            @return возвращает развернутый шаблон
        */
        private function do_insert_blocks($tpl_content, $tpl_path, $parent_file = '')
        {
            // получение списока INSERT блоков
            $insert_blocks = $this->find_blocks('INSERT', $tpl_content);
            if (!$insert_blocks)
                return $tpl_content;

            // обработка каждого INSERT блока
            foreach ($insert_blocks as $insert_block)
            {
                foreach ($insert_block as $tpl_file_name => $preassign_content);
                
                if (!file_exists($tpl_path . $tpl_file_name)) {
                    echo "Can not find teamplate file '" . 
                         $tpl_path . $tpl_file_name .
                         "' in parent teamplate file: '" . $parent_file . "'\n";
                    exit;
                }
                
                // Загрузка шаблона указанного в INSERT блоке
                $insert_content = file_get_contents($tpl_path . $tpl_file_name);

                // удаление коментариев /* */ и //
                $insert_content = $this->strip_comments($insert_content);

                // Получение древовидного массива всех блоков с метками для ассайна их в заргужаемый шаблон
                $preassign_data = $this->get_preassign_data($preassign_content);

                // асайн древовидного массива статический меток
                $preassign_tpl = new strontium_tpl;
                $preassign_tpl->open_buffer($insert_content);
                $preassign_tpl->assign(0, $preassign_data['marks']);

                // ассайн дерева блоков с меткаими в заргужаемый шаблон
                $preassign_tpl->assign_array($preassign_data);
                $preassigned_content = $preassign_tpl->result_content;

                // поиск временных блоковых меток и замена их на заполненное содержимое этих блоков
                preg_match_all("/<<-(.*)->>/Us", $preassigned_content, $extract);
                $list_blocks = $extract[1];
                if ($list_blocks)
                    foreach ($list_blocks as $block_name) {
                        $block_src = $this->find_block('BLOCK', $block_name, $insert_content);
                        $preassigned_content = str_replace("<<-" . $block_name . "->>",
                                                           '<!-- START BLOCK : ' .
                                                           $block_name .
                                                           ' -->' .
                                                           $block_src .
                                                           '<!-- END BLOCK : ' .
                                                           $block_name .
                                                           ' -->', $preassigned_content);
                    }
                    
                // поскольку вместо INSERT блока вставляется содержимое этого блока,
                // то если в содержимом блоке встретится INSERT блок, то к нему надо дописать текущий путь.
                
                $insert_path_prefix = $this->get_file_path($tpl_file_name);
                // рекурсивный запуск для поиска новых INSERT блоков в загруженном шаблоне
                $preassigned_content = $this->do_insert_blocks($preassigned_content,
                                                               $tpl_path . $insert_path_prefix,
                                                               $tpl_file_name);

                // замена текущего INSERT блока сформированными данными
                $tpl_content = $this->replace_block('INSERT',
                                                    $tpl_content,
                                                    $tpl_file_name,
                                                    $preassigned_content);
            }

            return $tpl_content;
        }
        
        
        /**
            Удаление комментариев вида // или / *   * /
            @param $text - исходный текст
            @return текст без комментариев
        */
        private function strip_comments($text)
        {
            $found_slash = 0; // Флаг информирующий нахождение символа '/'
            $finded_quote = 0; // Флаг информирующий нахождение одинарной ковычки
            $finded_doble_quote = 0; // Флаг информирующий нахождение двойной ковычки
            $comment_opened = 0; // Флаг информирующий нахождение позиции открытия коментария
            $one_string_comment_opened = 0; // Флаг информирующий нахождение позиции открытия однострочного коментария
            $start_comment = 0; // Адрес начала найденного комментария
            $length = strlen($text); // Длинна всего текста
            
            // Перебор текста по символьно
            for($p = 0; $p < $length; $p++) {
                switch($text[$p]) {

                case '/':
                    // Если этот символ встретился внутри одинарной или двойной ковычки
                    if ($finded_quote || $finded_doble_quote)
                        break;

                    // Если предыдущий символ * а следующий / и ранее было найдено начало комментария,
                    // значит это конец этого комментария
                    if ($found_star && $comment_opened && !$one_string_comment_opened) {
                        $end_comment = $p + 1; // конец комментария
                        $found_star = 0; // опускаем флаг нахождения *
                        $comment_opened = 0; // закрытие режима комментария
                        
                        // Удаление комментария
                        $before = substr($text, 0, $start_comment); 
                        $after = substr($text, $end_comment, $length);
                        $text = $before . $after; 
                        // уменьшение длинны текста на длинну вырезанного комментария
                        $length -= $end_comment - $start_comment;
                        // уменьшение позиции указателя на длинну вырезанного комментария
                        $p -= $end_comment - $start_comment;
                        $found_slash = 0;
                        break;
                    }
                    
                    // Если предыдущий символ / и следующий / значит найден однострочный комментарий
                    if ($found_slash && !$one_string_comment_opened) {
                        $one_string_comment_opened = 1;
                        $found_slash = 0;
                        // Сохранение поциции начала комментария
                        $start_comment = $p - 1;
                        break;
                    }
                    
                    $found_slash = 1;
                break;
                
                case '*':
                    // Если символ встретился внутри одинарной или двойной ковычки
                    if ($finded_quote || $finded_doble_quote)
                        break;

                    // Если предыдущий символ / а следующий * и найден не в нутри комментария,
                    // значит это начало нового комментария                    
                    if ($found_slash && !$comment_opened && !$one_string_comment_opened) {
                        $start_comment = $p - 1; // Сохраняем поцицию начала комментария
                        $found_slash = 0; // опускаем флаг нахождения /
                        $comment_opened = 1; // Открываем режим комментария
                    }
                    // Если предыдущий символ не '/' тогда устанавливается флаг нахождения символа '*'
                    else
                        $found_star = 1;
                break;
                
                case "'": // если найдена одинарная ковычка
                    // Если одинарная ковычка найденна внутри двойных
                    if ($finded_doble_quote)
                        break;
                    
                    if ($comment_opened || $one_string_comment_opened)
                        break;
                    
                    $finded_quote = !$finded_quote;
                break;
                
                case '"': // если найдена двойная ковычка
                    // Если двойная ковычка найденна внутри одинарных
                    if ($finded_quote)
                        break;

                    if ($comment_opened || $one_string_comment_opened)
                        break;
                        
                    $finded_doble_quote = !$finded_doble_quote;
                break;
                
                // Если найден конец строки
                case "\n":
                    // Если небыло найденно однострочного комметария
                    if(!$one_string_comment_opened)
                        break;
                    
                    $end_comment = $p + 1;// конец однострочного комментария
                    
                    // Удаляем комментарий
                    $before = substr($text, 0, $start_comment); 
                    $after = substr($text, $end_comment, $length);
                    $text = $before . $after; 

                    //Уменьшаем длинну текста на длинну вырезанного комментария
                    $length -= $end_comment - $start_comment;

                    // Уменьшаем позицию указателя на длинну вырезанного комментария
                    $p -= $end_comment - $start_comment;
                    $one_string_comment_opened = 0;
                break;
                
                default:
                    // В случае находждения любого отличного символа,
                    // опускаем флаги нахождения слэша или звездочки
                    $found_slash = 0;
                    $found_star = 0;
                }
            }
            
            return $text;
        }        
        
        
        /**
            Сформировать имена блоков для компилируемых шаблонов
            @param $block_name - название блока
            @return префикс для названия функций и массивов в компилируемом шаблоне
        */
        private function get_tpl_compile_block_name($block_name)
        {
            return 'compiled_block_' . $block_name;
        }
        
        
        /**
            Рекурсивная компиляция блоков
            @param $block_text - Исходный текст шаблона
            @param $current_block_name - имя текущего компилируемого блока
            @return скомпилированный PHP код шаблона
        */
        private function tpl_compile_blocks($block_text, $current_block_name = 'root')
        {
            $block_code = '';
            
            $blocks = $this->find_blocks('BLOCK', $block_text);
            foreach ($blocks as $block) {
                foreach($block as $block_name => $block_data);
                // сохранение содержимого найденного блока
                $list_blocks[$block_name] = $block_data;
                // в результирующем контенте удаляется содержимое блоков
                // вмеcто блоков временно ставятся блоковые метки в формате <<-имя_блока->>
                $block_text = $this->replace_block("BLOCK",
                                                   $block_text,
                                                   $block_name,
                                                   '" . $' .
                                                   $this->get_tpl_compile_block_name($block_name) .
                                                   ' . "');
            }
            
            $block_text = preg_replace("/{(\w+)}/Us",
                                        "\". \$block_" . $current_block_name . "['$1'] .\"",
                                        $block_text);
            $block_text = str_replace("\r", '\r', $block_text);
            $block_text = str_replace("\n", '\n', $block_text);
            $block_text = '$' . $this->get_tpl_compile_block_name($current_block_name) .
                          ' .= "' . $block_text . '";' . "\n";
            
            if ($list_blocks)
                foreach ($list_blocks as $block_name => $block_data) {
                    $compile_block = '';
                    
                    $compile_block = $this->tpl_compile_blocks($block_data, $block_name);

                    $compile_code = "\$" . $this->get_tpl_compile_block_name($block_name) . " = '';\n";
                    $compile_code .= "if(\$block_" . $current_block_name . "['<blocks>']['" . $block_name . "'])\n";
                    $compile_code .= "foreach(\$block_" . $current_block_name .
                                     "['<blocks>']['" . $block_name . "'] as \$block_" . $block_name . "){\n";
                    $compile_code .= $compile_block;
                    $compile_code .= "}\n";
                    $block_text = $compile_code . $block_text;
                }
            
            
            return $block_text;
        }
        
        
        /**
            Рекурсивная функция генерации дерева блоков.
            Используется функцией assign() для ориентации в дереве блоков
            @param $block_text - текст шаблона
            @return дерево блоков в формате (имя_блока => массив с подблоками)
        */
        private function tpl_create_block_tree($block_text)
        {
            $list_blocks_tree = array();
            
            $blocks = $this->find_blocks('BLOCK', $block_text);
            foreach ($blocks as $block) {
                foreach ($block as $block_name => $block_data);
                $list_blocks_tree[$block_name] = $this->tpl_create_block_tree($block_data);
            }
            
            return $list_blocks_tree;
        }
        
        
        /**
            Компиляция массива в PHP программный код
            @param $arr - ассоциативный массив
            @return PHP код формирующий данный массив
        */
        private function array_compile($arr)
        {
            foreach ($arr as $key => $val)
                $str .= "'" . $key . "' => array(" . $this->array_compile($val) . "),";
            
            return $str;
        }
        
        
        /**
            Компилирует шаблон и сохраняет его в файл $filename с расширением php
            @param $tpl_content - текст шаблона
            @param $filename - имя файла шаблона
        */
        private function tpl_compile_teamplate($tpl_content, $filename)
        {
            // Формируем дерево блоков для шаблона
            $tree['root'] = $this->tpl_create_block_tree($tpl_content);
            
            // Формирование PHP кода дерева блоков в шаблоне
            $tpl_tree = "if(!\$run_teamplate)\n\t\$this->compiled_struct_tree = array(" . 
                        $this->array_compile($tree) . ');';
            
            // Экранирование символов \ $ "
            $tpl_content = str_replace('\\', '\\\\', $tpl_content);
            $tpl_content = str_replace('$', '\\$', $tpl_content);
            $tpl_content = str_replace('"', '\\"', $tpl_content);
            
            // Компиляция шаблона
            $compile_code = $this->tpl_compile_blocks($tpl_content);
            
            // Формирование содержимого файла шаблона
            $compile_code = "<?php\n " .$tpl_tree . 
                            "\n\nif(\$run_teamplate){\n\$compiled_block_root = '';\n" .
                            $compile_code .
                            "\n }\n ?>";
            
            // Сохранение файла в каталог .compiled
            $path = $this->get_file_path($filename);
            $file = $this->get_file_name($filename);
            
            @mkdir($path . '.compiled/');
            file_put_contents($path . '.compiled/' . $file . '.php', $compile_code);
        }


        /**
            Рекурсивное формирование списка дочерних блоков из структуры дерева блоков
            (используется в режими компиляции)
            @param $parent_block - родительский блок
            @param $tree - Дерево блоков
        */
        private function get_children_blocks_by_tree($parent_block, $tree)
        {
            foreach($tree as $block_name => $sub_block_list) {
                // рекурсивный поиск заданного блока в подблоке
                $list = $this->get_children_blocks_by_tree($parent_block, $sub_block_list);
                if ($list)
                    return $list;
                    
                if ($block_name == $parent_block) {
                    // формирование списка дочерних блоков
                    foreach($sub_block_list as $sub_block_name => $sub_block_array)
                        $list[] = $sub_block_name;
                        
                    return $list;
                }
            }
            return false;
        }
        
        
        /**
            Добавить данные блока в дерево блоков (используется в режими компиляции)
            @param $stack - путь к родительскому блоку
            @param $data - данные которые надо добавить в дерево блоков
        */
        private function add_node_to_assign_tree($stack, $data)
        {
            // Извлечение последнего блока из списка,
            // этот блок является точкой назначения и потому для него особый алгоритм
            $last_block = array_pop($stack);
            
            // Указатель $p в цикле будет двигаться по дереву до родительского блока (не включая последний)
            $p = &$this->assign_tree;
            foreach($stack as $item) {
                $count_blocks = count($p['<blocks>'][$item]);
                $p = &$p['<blocks>'][$item][$count_blocks ? ($count_blocks - 1) : 0];
            }
            
            // После того как добрались до родительского элемента,
            // добавлется еще одна копия дочернего блока с данными $data
            $count_blocks = count($p['<blocks>'][$last_block]);
            $p['<blocks>'][$last_block][$count_blocks] = $data;
        }
    }
    
    


    /**
        Получить список блоков.
        @param $filename - Имя файла шаблона
        @return массив вида (имя_блока => содержимое_блока)
    */
    function tpl_get_blocks($filename)
    {
        $tpl = new strontium_tpl;
        return $tpl->open($filename, array(), false);
    }


    /**
        Заполнить шаблон и вернуть результат.
        @param $tpl_content - Содержимое шаблона
        @param $data - Список меток и их значений
        @return Заполненный шаблон
    */
    function tpl_assign($tpl_content, $data)
    {
        $tpl = new strontium_tpl('', array(), false);
        $tpl->open_buffer($tpl_content);
        $tpl->assign(0, $data);
        return $tpl->make_result();
    }

?>
