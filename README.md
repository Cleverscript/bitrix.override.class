# Перегрузка классов ядра 1С-Bitrix

---

Пример - нужно в списке задач, скрыть те у которых значения пользовательского поля,
с типом "Да\Нет" установлено как "Нет" (критерий может быть любой другой).

Для начала, нам нужно найти необходимый класс для переопределения,
для этого в компоненте который выводит список задач (или любой другой грид),
найти строку в которой собственно происходит получение данных, которые попадают в $arResult и выводятся в шаблоне.

Ниже приведен путь поиска такого класса, для задач:

Шаблон компонента `socialnetwork_user` в котором выводится компонент с задачами
```
/bitrix/components/bitrix/socialnetwork_user/templates/.default/user_tasks.php
```

в нем подключается сам компонент
```code
bitrix:tasks.task.list
```

В его class.php задачи тянуться вызовом
```php
$mgrResult = Manager\Task::getList(User::getId(), $getListParameters, $parameters);
```
Manager\Task
```code
/bitrix/modules/tasks/lib/manager/task.php
```

в методе getList идет вызов
```php
\CTaskItem::fetchListArray
```
это класс
```code
/bitrix/modules/tasks/classes/general/taskitem.php
```

---

Создаем пользовательское св-во тип "Да/Нет" для объекта TASKS_TASK, по этому полю будем фильтровать задачи.

Создаем класс который будет переопределять класс ядра
```code
local/classes/Override/CTaskItem.php
```

В файле
```code
local/php_interface/init.php
```

Регистрируем свой кастомный автолоадер, который будет выполнять действия по подгрузке в ОЗУ, 
модифицированного кода класса CTaskItem модуля ядра tasks.

Суть модификации заключается в замене имени  интерфейса
```php
interface ___VirtualCTaskItemInterface
```
и класса
```php
class ___VirtualCTaskItem implements ___VirtualCTaskItemInterface, ArrayAccess
```

Далее создаем непосредственно свой класс, с названием аналогичным классу из ядра CTaskItem, код которого также будет загружен в ОЗУ и модифицирован таким образом что он будет наследоваться уже от "виртуального" класса, вот таким образом
```php
final class CTaskItem extends ___VirtualCTaskItem implements ___VirtualCTaskItemInterface, ArrayAccess
```

Класс
```
local/classes/Override/CTaskItem.php
```

В этом классе мы переопределяем метод `fetchListArray`
и в нем добавляем в фильтр, значение для фильтрации по пользовательскому полю
```php
$arFilter['UF_SHOW'] = true;
```

Распечатаем содержимое фильтра, и там будет такое
```code
Array
(
    [::SUBFILTER-ROLEID] => Array
        (
            [MEMBER] => 1
        )

    [CHECK_PERMISSIONS] => Y
    [UF_SHOW] => 1
)
```

В таблице `b_uts_tasks_task` можно проставить 1 в поле UF_SHOW нужным задачам, что бы они вывелись в списке.

