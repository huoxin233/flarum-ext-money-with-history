import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';

interface UserMoneyModalAttrs extends IInternalModalAttrs {
  user: User;
}

export default class UserMoneyModal extends Modal<UserMoneyModalAttrs> {
  money!: Stream<number>;

  oninit(vnode: Mithril.Vnode<UserMoneyModalAttrs>) {
    super.oninit(vnode);

    this.money = Stream(this.attrs.user.attribute<number>('money') || 0);
  }

  className(): string {
    return 'UserMoneyModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('huoxin-money-with-history.forum.modal.title', { user: this.attrs.user });
  }

  content(): Mithril.Children {
    const moneyName = app.forum.attribute<string>('huoxin-money-with-history.moneyname') || '[money]';

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>
              {app.translator.trans('huoxin-money-with-history.forum.modal.current')}{' '}
              {moneyName.replace('[money]', String(this.attrs.user.attribute<number>('money')))}
            </label>
            <input required className="FormControl" type="number" step="any" bidi={this.money} />
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary" type="submit" loading={this.loading}>
              {app.translator.trans('huoxin-money-with-history.forum.modal.submit_button')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    this.loading = true;

    this.attrs.user
      .save({ money: this.money() }, { errorHandler: this.onerror.bind(this) })
      .then(this.hide.bind(this))
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
