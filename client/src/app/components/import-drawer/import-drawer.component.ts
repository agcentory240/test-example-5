import { Component, EventEmitter, Input, Output } from '@angular/core';
import { DrawerModule } from 'primeng/drawer';
import { FileUploadModule } from 'primeng/fileupload';
import { ButtonModule } from 'primeng/button';
import { ProgressBarModule } from 'primeng/progressbar';

@Component({
  selector: 'app-import-drawer',
  standalone: true,
  imports: [DrawerModule, FileUploadModule, ButtonModule, ProgressBarModule],
  template: `
    <p-drawer
      [visible]="visible"
      (visibleChange)="visibleChange.emit($event)"
      position="right"
      header="Импорт товаров"
      [style]="{ width: '450px' }"
    >
      <div class="drawer-body">
        <p-fileupload
          mode="basic"
          accept=".xlsx"
          chooseLabel="Выбрать файл"
          [auto]="false"
          [customUpload]="true"
        />

        <button pButton type="button" label="Загрузить" [disabled]="true"></button>

        <p-progressbar mode="indeterminate" [style]="{ height: '6px' }" />

        <section class="drawer-history">
          <h3>История импортов</h3>
          <p>Нет импортов</p>
        </section>
      </div>
    </p-drawer>
  `,
  styles: [
    `
      .drawer-body {
        display: flex;
        flex-direction: column;
        gap: 1rem;
      }

      .drawer-history h3 {
        margin: 0 0 0.5rem;
        font-size: 1rem;
      }

      .drawer-history p {
        margin: 0;
        color: var(--p-text-muted-color, #6b7280);
      }
    `,
  ],
})
export class ImportDrawerComponent {
  @Input() visible = false;
  @Output() visibleChange = new EventEmitter<boolean>();
}
